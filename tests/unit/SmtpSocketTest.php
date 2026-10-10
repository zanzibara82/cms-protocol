<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use Cms\Core\SmtpSocket;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Протокольный слой почты проверяется против подставного сервера на петлевом адресе: ветка отказа
 * проверяется отказом, удачная доставка — доставкой, и ни одна проверка здесь не пропускается —
 * почтовый контейнер и его настройки не нужны.
 *
 * Клиент живёт в дочернем процессе (`tests/fixtures/smtp/client-worker.php`): `SmtpSocket::open()`
 * блокируется на чтении ответа, поэтому однопоточный тест не может одновременно печатать серверные
 * ответы и ждать от клиента следующую команду. Сервером — и судьёй по расшифровке разговора —
 * остаётся сам тест.
 */
final class SmtpSocketTest extends TestCase
{
    private const HOST = '127.0.0.1';

    private const WORKER = '/tests/fixtures/smtp/client-worker.php';

    /** Порт отправки из `config.sample.php`: шифрование на нём обязательно. */
    private const SUBMISSION_PORT = 587;

    /** Порт, где канал шифруется с самого начала (`ssl://`). */
    private const IMPLICIT_TLS_PORT = 465;

    private static string $certificateFile = '';

    private static string $certificateKeyFile = '';

    /** Сертификат с чужим именем: рукопожатие с ним возможно, а вот проверка имени — нет. */
    private static string $otherNameFile = '';

    private static string $otherNameKeyFile = '';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, string> */
    private array $tempFiles = [];

    private int $port = 0;

    private string $logFile = '';

    private string $logFileBefore = '';

    public static function setUpBeforeClass(): void
    {
        // Сертификаты нужны проверкам шифрования: на «своём» имени видно, что клиент согласует
        // канал и войдёт в него, на чужом — что он сверяет имя, а не принимает любое рукопожатие.
        // Строятся один раз на класс: сборка ключа дольше самой проверки, а меняться им негде.
        [self::$certificateFile, self::$certificateKeyFile] = self::createCertificate('/CN=' . self::HOST, '');
        [self::$otherNameFile, self::$otherNameKeyFile] = self::createCertificate('/CN=smtp.example.org', 'DNS:smtp.example.org');
    }

    /**
     * Самоподписанный сертификат подставного сервера. Возвращает пару путей: публичная часть
     * годится и клиенту как явный список доверия (`-d openssl.cafile=`), чтобы шагам шифрования
     * и входа нашлось исполняемое продолжение — без новых ключей настроек ядра.
     *
     * @return array{0: string, 1: string}
     */
    private static function createCertificate(string $subject, string $alternativeName): array
    {
        $base = sys_get_temp_dir() . '/smtp-self-signed-' . newId();
        $command = sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 2 -nodes -subj %s%s',
            escapeshellarg($base . '.key'),
            escapeshellarg($base . '.pem'),
            escapeshellarg($subject),
            $alternativeName === '' ? '' : ' -addext subjectAltName=' . escapeshellarg($alternativeName)
        );
        $output = [];

        exec($command . ' >/dev/null 2>&1', $output, $status);

        if ($status !== 0 || !is_file($base . '.pem') || !is_file($base . '.key')) {
            self::fail('подставному серверу нужен openssl для самоподписанного сертификата: код ' . $status . '.');
        }

        return [$base . '.pem', $base . '.key'];
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$certificateFile, self::$certificateKeyFile, self::$otherNameFile, self::$otherNameKeyFile] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    protected function setUp(): void
    {
        $this->logPathBefore();
        $this->logFile = sys_get_temp_dir() . '/smtp-socket-test-' . newId() . '.log';

        // Дочерний процесс наследует окружение, поэтому запись о незашифрованном канале появляется
        // в этом временном файле, а журнал ядра остаётся нетронутым.
        putenv('CMS_LOG_PATH=' . $this->logFile);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->server = null;

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];

        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }

        putenv('CMS_LOG_PATH=' . $this->logFileBefore);
    }

    public function testБезОбъявленногоШифрованияИЗаполненногоПользователяЛогинНеОтправляется(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('kluch@example.com', 'пароль-секрет', ['ehlo' => '250 подставной сервер']);

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('SMTP_USER', $answer);
        self::assertSame([], $this->commands($received, 'AUTH'), 'логин ушёл бы открытым текстом');
        self::assertStringNotContainsString('пароль-секрет', $answer);
        self::assertStringNotContainsString(base64_encode('пароль-секрет'), $answer);
    }

    public function testПорт587БезОбъявленногоSTARTTLSНеОтправляетПисьмоВовсе(): void
    {
        $this->bind(self::SUBMISSION_PORT);
        [$answer, $received] = $this->runClient('', '', ['ehlo' => '250 подставной сервер'], $this->rawMessage());

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('587', $answer);
        self::assertStringContainsString('STARTTLS', $answer);
        self::assertSame([], $this->commands($received, 'MAIL FROM'), 'на порту отправки письмо не должно уходить без шифрования');
        self::assertSame([], $this->commands($received, 'DATA'));
    }

    public function testПортВне587БезШифрованияОтправляетПисьмоИОставляетЗаписьВЖурнале(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('', '', ['ehlo' => '250 подставной сервер'], $this->rawMessage());

        self::assertSame('OK', $answer);
        self::assertContains('MAIL FROM:<sender@example.com>', $received);
        self::assertContains('RCPT TO:<uchenik@example.com>', $received);
        self::assertStringContainsString('mail.plaintext', $this->log());
        self::assertStringContainsString('"port"', $this->log());
        self::assertStringNotContainsString('Текст письма', $this->log());
    }

    public function testSTARTTLSВЧужомТекстеВозможностиНеЗаставляетСлатьSTARTTLS(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient(
            '',
            '',
            [
                'ehlo' => "250-SIZE 30 (используйте STARTTLS)\r\n250 OK",
                'starttls' => '220 2.0.0 Ready to start TLS',
                'after-starttls' => '421 это не рукопожатие TLS',
            ],
            $this->rawMessage()
        );

        // Возможность опознаётся по имени в начале строки ответа, а не по подстроке всего транскрипта:
        // текст про размер письма не обязан уводить клиента в рукопожатие, которого сервер не ведёт.
        self::assertSame('OK', $answer);
        self::assertSame([], $this->commands($received, 'STARTTLS'));
    }

    public function testSTARTTLSОбъявленныйИменемСтрокиОтправляется(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('', '', [
            'ehlo' => "250-STARTTLS\r\n250 OK",
            'starttls' => '502 5.5.2 команда не реализована',
        ]);

        self::assertStringStartsWith('ERR', $answer);
        self::assertNotEmpty($this->commands($received, 'STARTTLS'), 'объявленную возможность клиент обязан использовать');
    }

    public function testСерверСогласилсяНаTLSНоРукопожатияНеВедёт(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('kluch@example.com', 'пароль-секрет', [
            'ehlo' => "250-STARTTLS\r\n250 OK",
            'starttls' => '220 2.0.0 Ready to start TLS',
            'after-starttls' => '421 это не рукопожатие TLS',
        ]);

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('STARTTLS', $answer);
        self::assertSame([], $this->commands($received, 'AUTH'), 'несостоявшееся рукопожатие не даёт права логиниться');
        self::assertStringNotContainsString(base64_encode('пароль-секрет'), implode("\n", $received));
    }

    public function testНепроверенныйСертификатСервераОтклоняется(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('kluch@example.com', 'пароль-секрет', [
            'ehlo' => "250-STARTTLS\r\n250 OK",
            'starttls' => '220 2.0.0 Ready to start TLS',
            'tls' => 'cert',
        ]);

        self::assertStringStartsWith('ERR', $answer);
        self::assertSame([], $this->commands($received, 'AUTH'), 'канал с непроверенным сертификатом не считается зашифрованным');
    }

    public function testСерверКоторыйПредставляетсяТолькоСтаройВерсиейTlsНеДоговаривается(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('', '', [
            'ehlo' => "250-STARTTLS\r\n250 OK",
            'starttls' => '220 2.0.0 Ready to start TLS',
            'tls' => 'cert',
            'tls-method' => 'tls1_0',
        ]);

        self::assertStringStartsWith('ERR', $answer);
        self::assertSame([], $this->commands($received, 'AUTH'));
        self::assertCount(1, $this->commands($received, 'EHLO'), 'после неудачного рукопожатия клиент не обязан повторять EHLO');
    }

    public function testПроверенныйКаналВходитИОтправляетПисьмоБезЗаписиОбОткрытомПере(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClientWithTrust(
            'kluch@example.com',
            'пароль-секрет',
            [
                'ehlo' => "250-STARTTLS\r\n250 OK",
                'starttls' => '220 2.0.0 Ready to start TLS',
                'tls' => 'cert',
            ],
            $this->rawMessage(),
            true
        );

        self::assertSame('OK', $answer);
        self::assertContains('AUTH LOGIN', $received);
        self::assertContains(base64_encode('kluch@example.com'), $received);
        self::assertContains('MAIL FROM:<sender@example.com>', $received);
        self::assertContains('RCPT TO:<uchenik@example.com>', $received);
        // Зашифрованный канал — штатный, и записи о пересылке открытым текстом он не оставляет.
        self::assertStringNotContainsString('mail.plaintext', $this->log());
    }

    public function testСерверНеПринялВходИПисьмоНеОтправлено(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClientWithTrust(
            'kluch@example.com',
            'пароль-секрет',
            [
                'ehlo' => "250-STARTTLS\r\n250 OK",
                'starttls' => '220 2.0.0 Ready to start TLS',
                'tls' => 'cert',
                'auth-pass' => '535 5.7.8 вход не принят',
            ],
            $this->rawMessage(),
            true
        );

        self::assertStringStartsWith('ERR', $answer);
        self::assertSame([], $this->commands($received, 'MAIL FROM'), 'непринятые учётные данные не дают права отправлять письмо');
    }

    public function testСтрокаСервераНаШагеВходаНеПопадаетВОтказ(): void
    {
        // Сервер во время входа может поставить в свой ответ то, что ему передали, или что угодно
        // ещё; текст отказа уходит в журнал, поэтому от шага входа остаётся только код.
        $this->bind(0);
        [$answer] = $this->runClientWithTrust(
            'kluch@example.com',
            'пароль-секрет',
            [
                'ehlo' => "250-STARTTLS\r\n250 OK",
                'starttls' => '220 2.0.0 Ready to start TLS',
                'tls' => 'cert',
                'auth-pass' => '535 5.7.8 метка-которую-сервер-не-должен-переносить-дальше',
            ],
            $this->rawMessage(),
            true
        );

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('535', $answer);
        self::assertStringNotContainsString('метка-которую-сервер-не-должен-переносить-дальше', $answer);
        self::assertStringNotContainsString(base64_encode('пароль-секрет'), $answer);
    }

    public function testСертификатСЧужимИменемНеСчитаетсяПровереннымКаналом(): void
    {
        // Тому же самому доверию, что принимает предыдущие проверки, сервер подставляет сертификат
        // на другое имя: канал обязан быть отвергнут, иначе проверка имени сертификата ничего не
        // стоит и письмо уйдёт посреднику, которым притворится кто угодно.
        $this->bind(0);
        [$answer, $received] = $this->runClientWithTrust(
            'kluch@example.com',
            'пароль-секрет',
            [
                'ehlo' => "250-STARTTLS\r\n250 OK",
                'starttls' => '220 2.0.0 Ready to start TLS',
                'tls' => 'other-name-cert',
            ],
            $this->rawMessage(),
            true
        );

        self::assertStringStartsWith('ERR', $answer);
        self::assertSame([], $this->commands($received, 'AUTH'), 'канал с чужим именем не считается зашифрованным');
        self::assertSame([], $this->commands($received, 'MAIL FROM'));
    }

    public function testНеполнаяЗаписьНеСчитаетсяОтправленнымЗапросом(): void
    {
        // Проверяется сама строгость записи: сокет делается неблокирующим и никем не читается,
        // буфер заполняется, и `fwrite` отдаёт меньше половины запроса. Недописанную строку
        // сервер ждал бы как часть команды, а письмо ушло бы искажённым.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        [$writer, $reader] = $pair;
        stream_set_blocking($writer, false);

        $socket = new SmtpSocket(self::HOST, 25, '', '', 1.0);
        $stream = new \ReflectionProperty(SmtpSocket::class, 'stream');
        $stream->setValue($socket, $writer);
        $write = new \ReflectionMethod(SmtpSocket::class, 'write');

        $message = '';

        try {
            $write->invoke($socket, str_repeat('x', 4 * 1024 * 1024));
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }

        self::assertStringContainsString('не весь запрос', $message, 'неполная запись не должна считаться отправленным запросом');

        // Закрытая читающая сторона: запись не удаётся вовсе — отказ обязан говорить о соединении.
        fclose($reader);
        $message = '';

        try {
            $write->invoke($socket, 'QUIT');
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }

        fclose($writer);
        self::assertStringContainsString('разорвал соединение', $message, 'оборванная запись не должна выглядеть успехом');
    }

    public function testТочкаВНачалеСтрокиУдваиваетсяАВнутриСтрокиНеТрогается(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient(
            '',
            '',
            ['ehlo' => '250 подставной сервер'],
            $this->rawMessage("первая строка\r\n.вторая с точкой\r\nтретья . внутри")
        );

        self::assertSame('OK', $answer);
        $data = $this->dataBlock($received);
        self::assertStringContainsString("\n..вторая с точкой", $data);
        self::assertStringNotContainsString("\n.вторая", $data);
        self::assertStringContainsString('третья . внутри', $data);
    }

    public function testМногострочныйОтветСервераЧитаетсяДоПоследнейСтроки(): void
    {
        $this->bind(0);
        [$answer] = $this->runClient('', '', ['ehlo' => "250-PIPELINING\r\n421 4.3.0 сервер уходит"]);

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('421', $answer);
        self::assertStringContainsString('вместо кода 250', $answer);
    }

    public function testПорт465НеПринимаетКаналБезTLS(): void
    {
        $this->bind(self::IMPLICIT_TLS_PORT);
        [$answer, $received] = $this->runClient('', '', ['greeting' => '220 подставной сервер без TLS']);

        self::assertStringStartsWith('ERR', $answer);
        self::assertStringContainsString('465', $answer);
        self::assertSame([], $this->commands($received, 'EHLO'), 'открытый SMTP на порту шифрования недопустим');
    }

    public function testПорт465ПринимаетЗашифрованныйКаналИДоставляетПисьмо(): void
    {
        // Порт 465 — единственный, где шифрование начинается до приветствия, и без удачной
        // доставки он оставался бы проверкой только отказа: клиент обязан согласовать канал сразу,
        // не отправляя STARTTLS, и пройти вход с доставкой по уже зашифрованному сокету.
        $this->bind(self::IMPLICIT_TLS_PORT);
        [$answer, $received] = $this->runClientWithTrust(
            'kluch@example.com',
            'пароль-секрет',
            [
                'implicit-tls' => 'on',
                'tls' => 'cert',
                'ehlo' => "250-подставной сервер\r\n250 HELP",
            ],
            $this->rawMessage(),
            true
        );

        self::assertSame('OK', $answer);
        self::assertSame([], $this->commands($received, 'STARTTLS'), 'на порту шифрования STARTTLS не нужен');
        self::assertContains('AUTH LOGIN', $received);
        self::assertContains('MAIL FROM:<sender@example.com>', $received);
        self::assertContains('RCPT TO:<uchenik@example.com>', $received);
        self::assertStringNotContainsString('mail.plaintext', $this->log());
    }

    public function testОбрывСоединенияВоВремяРазговораНеСчитаетсяУспехом(): void
    {
        $this->bind(0);
        [$answer, $received] = $this->runClient('', '', ['hangup' => 'после первой команды']);

        self::assertStringStartsWith('ERR', $answer);
        self::assertNotEmpty($received, 'клиент обязан был передать хотя бы одну команду');
        self::assertTrue(
            str_contains($answer, 'разорвал соединение')
                || str_contains($answer, 'закрыл соединение')
                || str_contains($answer, 'не весь запрос'),
            'отказ обязан быть о потере соединения, получено: ' . $answer
        );
    }

    /**
     * Запускает слушателя на петлевом адресе. Нулевой порт выдаёт операционная система, а порты
     * 587 и 465 занимаются явно: иначе ветки, которые зависят от номера порта, проверить нечем.
     * В dev-контейнере оба свободны (приёмник почты слушает 1025), а процесс под root их берёт.
     */
    private function bind(int $port): void
    {
        $errno = 0;
        $error = '';
        $server = @stream_socket_server('tcp://' . self::HOST . ':' . $port, $errno, $error);

        if (!is_resource($server)) {
            self::fail('подставной сервер не слушает ' . self::HOST . ':' . $port . ' — ' . $error);
        }

        $name = (string) stream_socket_get_name($server, false);
        $this->server = $server;
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Разговор с клиентом: приветствие, затем ответы по имени команды. Возвращает расшифровку —
     * список строк, которые клиент действительно передал, и по нему видно в том числе то, чего
     * клиент послать не должен. Строка, не похожая ни на одну известную команду (неожиданный
     * `AUTH LOGIN`), остаётся без ответа: клиент упрётся в свой таймаут, а находка попадёт
     * в расшифровку.
     *
     * @param resource $server
     * @param array<string, string> $script
     * @return array<int, string>
     */
    private function converse($server, array $script): array
    {
        $client = @stream_socket_accept($server, 8.0);

        if (!is_resource($client)) {
            self::fail('почтовый клиент не подключился к подставному серверу за 8 секунд.');
        }

        stream_set_timeout($client, 4);
        $received = [];
        // Шаг входа состоит из трёх обменов, а два из них — голые строки base64, ни на одну
        // команду не похожие: сервер ведёт их по состоянию, а не по началу строки.
        $awaiting = null;

        // Порт 465 шифруется с первой же секунды, поэтому рукопожатие сервер ведёт до
        // приветствия: клиент, пришедший на «ssl://», ждёт его раньше любой строки.
        if (($script['implicit-tls'] ?? '') === 'on') {
            $this->startTls($client, $script);
        }

        $this->reply($client, $script, 'greeting', '220 подставной сервер ESMTP');

        while (true) {
            $line = fgets($client);

            if ($line === false || $line === '') {
                break;
            }

            $line = rtrim($line, "\r\n");
            $received[] = $line;

            if (isset($script['hangup'])) {
                break;
            }

            $command = strtoupper($line);

            if ($awaiting === 'user') {
                $awaiting = 'pass';
                $this->reply($client, $script, 'auth-user', '334 ' . base64_encode('Password:'));
            } elseif ($awaiting === 'pass') {
                $awaiting = null;
                $this->reply($client, $script, 'auth-pass', '235 2.0.0 Ok: authenticated');
            } elseif ($command === 'AUTH LOGIN') {
                $awaiting = 'user';
                $this->reply($client, $script, 'auth', '334 ' . base64_encode('Username:'));
            } elseif (str_starts_with($command, 'EHLO') || str_starts_with($command, 'HELO')) {
                $this->reply($client, $script, 'ehlo', "250 подставной сервер\r\n250 HELP");
            } elseif (str_starts_with($command, 'STARTTLS')) {
                $this->reply($client, $script, 'starttls', '502 5.5.2 команда не реализована');
                $this->startTls($client, $script);
            } elseif (str_starts_with($command, 'MAIL FROM')) {
                $this->reply($client, $script, 'mail-from', '250 2.1.0 Ok');
            } elseif (str_starts_with($command, 'RCPT TO')) {
                $this->reply($client, $script, 'rcpt-to', '250 2.1.5 Ok');
            } elseif ($command === 'DATA') {
                $this->reply($client, $script, 'data', '354 End data with <CR><LF>.<CR><LF>');
                $received[] = $this->readData($client);
                $this->reply($client, $script, 'message', '250 2.0.0 Ok: queued');
            } elseif (str_starts_with($command, 'QUIT')) {
                $this->reply($client, $script, 'quit', '221 2.0.0 Bye');
                break;
            }
        }

        fclose($client);

        return $received;
    }

    /**
     * Публичная часть сертификата, который сервер покажет по скрипту проверки. Она же служит
     * списком доверия для дочернего процесса: так шагам шифрования и входа находится исполняемое
     * продолжение, не требующее новых ключей настроек ядра.
     *
     * @param array<string, string> $script
     */
    private static function servedCertificate(array $script): string
    {
        return ($script['tls'] ?? '') === 'other-name-cert' ? self::$otherNameFile : self::$certificateFile;
    }

    /**
     * Приватная часть того же сертификата.
     *
     * @param array<string, string> $script
     */
    private static function servedKey(array $script): string
    {
        return ($script['tls'] ?? '') === 'other-name-cert' ? self::$otherNameKeyFile : self::$certificateKeyFile;
    }

    /**
     * Прогон клиента против готового слушателя.
     *
     * @param array<string, string> $script
     * @return array{0: string, 1: array<int, string>}
     */
    private function runClient(string $user, string $pass, array $script, string $raw = ''): array
    {
        return $this->runClientWithTrust($user, $pass, $script, $raw, false);
    }

    /**
     * Тот же прогон с выбором доверия: когда дочерний процесс знает о сертификате подставного
     * сервера заранее, рукопожатие проходит, и проверяются шаги, до которых без шифрования дело
     * не доходит, — вход и доставка по защищённому каналу.
     *
     * @param array<string, string> $script
     * @return array{0: string, 1: array<int, string>}
     */
    private function runClientWithTrust(string $user, string $pass, array $script, string $raw, bool $trustServedCertificate): array
    {
        $server = $this->server;

        if (!is_resource($server)) {
            self::fail('подставный сервер не запущен: проверка обязана вызвать bind() до разговора.');
        }

        $messageFile = '';

        if ($raw !== '') {
            $messageFile = $this->tempFile();
            file_put_contents($messageFile, $raw);
        }

        $command = [escapeshellarg(PHP_BINARY)];

        if ($trustServedCertificate) {
            // Доверие задаётся стартовым ключом дочернего процесса, а не настройкой ядра: ядро не
            // должно знать про тестовый сертификат, как не должно знать и про собственный `cafile`.
            $command[] = '-d ' . escapeshellarg('openssl.cafile=' . self::servedCertificate($script));
        }

        $command = array_merge($command, [
            escapeshellarg(dirname(__DIR__, 2) . self::WORKER),
            escapeshellarg(self::HOST),
            escapeshellarg((string) $this->port),
            escapeshellarg($user),
            escapeshellarg($raw === '' ? 'open' : 'deliver'),
            escapeshellarg($messageFile),
        ]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(implode(' ', $command), $descriptors, $pipes);

        if (!is_resource($process)) {
            self::fail('не удалось запустить дочерний процесс почтового клиента.');
        }

        // Пароль — в стандартный поток ввода: командная строка дочернего процесса видна всем,
        // кто смотрит список процессов.
        fwrite($pipes[0], $pass);
        fclose($pipes[0]);

        $received = $this->converse($server, $script);
        $answer = trim($this->readAll($pipes[1]));
        $stderr = trim($this->readAll($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_get_status($process)['running'] === true) {
            proc_terminate($process, 9);
        }

        proc_close($process);

        if ($answer === '') {
            self::fail('почтовый клиент не ответил в стандартный вывод; поток ошибок: ' . $stderr);
        }

        return [$answer, $received];
    }

    /**
     * @param resource              $client
     * @param array<string, string> $script
     */
    private function reply($client, array $script, string $key, string $default): void
    {
        @fwrite($client, ((string) ($script[$key] ?? $default)) . "\r\n");
    }

    /**
     * Серверная половина обещанного `STARTTLS`. Без ключа `tls` в скрипте сервер рукопожатие не
     * ведёт вовсе (и может напечтать что попало), с `tls` — ведёт его с самоподписанным сертификатом
     * на имя петлевого адреса, с `other-name-cert` — с сертификатом чужого имени, а `tls-method`
     * ограничивает версию протокола.
     *
     * @param resource              $client
     * @param array<string, string> $script
     */
    private function startTls($client, array $script): void
    {
        if (!isset($script['tls'])) {
            if (isset($script['after-starttls'])) {
                @fwrite($client, ((string) $script['after-starttls']) . "\r\n");
            }

            return;
        }

        // Сертификат подставного сервера задаётся опциями самого потока: четвёртый аргумент
        // stream_socket_enable_crypto — это ключ пира, а не контекст.
        stream_context_set_option($client, [
            'ssl' => [
                'local_cert' => self::servedCertificate($script),
                'local_pk' => self::servedKey($script),
                'verify_peer' => false,
            ],
        ]);
        $method = ($script['tls-method'] ?? '') === 'tls1_0'
            ? STREAM_CRYPTO_METHOD_TLSv1_0_SERVER
            : STREAM_CRYPTO_METHOD_TLS_SERVER;

        // Исход рукопожатия здесь не важен: клиент обязан отказаться сам, а тест судит по
        // расшифровке разговора и по отказу клиента.
        @stream_socket_enable_crypto($client, true, $method);
    }

    /**
     * Читает тело письма до строки-терминатора «.». Возвращается одним элементом расшифровки,
     * чтобы проверка видела удвоение точек ровно так, как его получил сервер.
     *
     * @param resource $client
     */
    private function readData($client): string
    {
        $block = [];

        while (true) {
            $line = fgets($client);

            if ($line === false || $line === '') {
                break;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '.') {
                break;
            }

            $block[] = $line;
        }

        return implode("\n", $block);
    }

    /** @param resource $stream */
    private function readAll($stream): string
    {
        stream_set_timeout($stream, 10);
        $data = stream_get_contents($stream);

        return $data === false ? '' : $data;
    }

    /**
     * Команды разговора с заданным началом.
     *
     * @param array<int, string> $received
     * @return array<int, string>
     */
    private function commands(array $received, string $prefix): array
    {
        return array_values(array_filter(
            $received,
            static fn (string $line): bool => str_starts_with(strtoupper($line), strtoupper($prefix))
        ));
    }

    /**
     * Блок данных письма, прочитанный сервером после `DATA`.
     *
     * @param array<int, string> $received
     */
    private function dataBlock(array $received): string
    {
        foreach ($received as $entry) {
            if (str_contains($entry, 'From: <')) {
                return $entry;
            }
        }

        return '';
    }

    /** Письмо в том виде, в каком его отдаёт `Mailer::render()`: заголовки, пустая строка, тело. */
    private function rawMessage(string $body = 'Текст письма.'): string
    {
        return "From: <sender@example.com>\r\nTo: <uchenik@example.com>\r\nSubject: Test\r\n\r\n" . $body;
    }

    private function tempFile(): string
    {
        $file = sys_get_temp_dir() . '/smtp-socket-message-' . newId();
        $this->tempFiles[] = $file;

        return $file;
    }

    /** Содержимое журнала, куда дочерний процесс пишет записи проверки. */
    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /** Путь журнала до того, как его переопределил тест: пустой означает «журнал ядра». */
    private function logPathBefore(): void
    {
        $this->logFileBefore = (string) (getenv('CMS_LOG_PATH') ?: '');
    }
}
