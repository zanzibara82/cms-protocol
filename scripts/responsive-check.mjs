// Проверка адаптивности 360…1440 (критерий §12.18, требование Global Constraints).
// Переполнение ищет на собранном стенде: поднимает headless Chrome с портом отладки, открывает
// страницы по протоколу отладки Chrome на шести экранах, ищет горизонтальное переполнение и
// пишет отчёт во временный каталог.
//
// Запуск (Node нужен только разработчику, внешних пакетов нет):
//   1) поднять стенд на тестовой базе — команды шага 8 этой задачи
//   2) npm run responsive -- --base=http://localhost:8080 --shots
//
// Вход нужен экранам админки: это не клики, а два запроса — GET формы (из неё берётся `_token`,
// задача 7) и POST с полями email/password. Поведение кнопок проверяют руками на шаге 11, здесь
// важна раскладка.
import { mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawn } from 'node:child_process'

const args = process.argv.slice(2)
const BASE = (args.find((a) => a.startsWith('--base=')) ?? '--base=http://localhost:8080').split('=')[1]
const SHOTS = args.includes('--shots')
const OUT = join(tmpdir(), 'cms-resp-check')

// Системный Chrome. Переменная CHROME_PATH перекрывает путь — на другой машине или Linux.
const CHROME =
  process.env.CHROME_PATH ??
  (process.platform === 'win32'
    ? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
    : '/usr/bin/google-chrome')

// Порт отладки занят только на время прогона: Chrome поднимается здесь же и здесь же убивается.
const PORT = 9333

const VIEWPORTS = [
  { name: '360', w: 360, h: 640 },
  { name: '390', w: 390, h: 844 },
  { name: '768', w: 768, h: 1024 },
  { name: '1024', w: 1024, h: 768 },
  { name: '1280', w: 1280, h: 800 },
  { name: '1440', w: 1440, h: 900 },
]

// Тестовые учётные данные установщика из шага 8 (cms_test), не боевой аккаунт.
const EMAIL = process.env.RESP_EMAIL ?? 'admin@school.dev'
const PASSWORD = process.env.RESP_PASSWORD ?? 'длинный-тестовый-пароль'
const SESSION_COOKIE = 'cms_session'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// Возвращает значение куки сессии или null: если вход не вышел, проверка не обрывается, а говорит
// об этом и берёт публичные адреса. Молчаливый пропуск админки — это «проверено 30 комбинаций»,
// когда надо было 78.
//
// Куки переносятся вручную: вход делается двумя запросами, а не страницей браузера, потому что
// Chrome поднимается позже и живёт в своём профиле. Токен CSRF лежит в `$_SESSION` (задача 7),
// поэтому кука из ответа на GET обязана уйти в POST. loginUser() вызывает
// session_regenerate_id(true) (задача 6), значит настоящая кука сессии приезжает в ответе на
// POST; это 302, и при `redirect: 'manual'` Node отдаёт его заголовки как есть — Set-Cookie
// читается. Решение принимает наличие куки, а не статус ответа.
async function loginCookie() {
  const jar = new Map()
  const keep = (res) => {
    for (const c of res.headers.getSetCookie() ?? []) {
      const [pair] = c.split(';')
      const i = pair.indexOf('=')
      jar.set(pair.slice(0, i).trim(), pair.slice(i + 1))
    }
  }
  const form = await fetch(`${BASE}/login`)
  keep(form)
  const token = /name="_token" value="([^"]+)"/.exec(await form.text())?.[1]
  if (!token) {
    console.error('на /login нет поля _token — вход невозможен, проверяются только публичные адреса')
    return null
  }
  const sent = await fetch(`${BASE}/login`, {
    method: 'POST',
    redirect: 'manual',
    headers: {
      'content-type': 'application/x-www-form-urlencoded',
      cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; '),
    },
    body: new URLSearchParams({ email: EMAIL, password: PASSWORD, _token: token }),
  })
  keep(sent)
  const value = jar.get(SESSION_COOKIE)
  if (!value) {
    console.error(
      `не удалось войти как ${EMAIL}: куки ${SESSION_COOKIE} нет — проверяются только публичные адреса`,
    )
    return null
  }
  return value
}

// Публичные адреса раздела берём из карты сайтов: материалы живут в базе, и выписать их руками
// значит проверить ленту и молча пропустить карточку с лонгридом. Ссылку на карту даёт robots.txt
// (§9), поэтому скрипт не должен знать, какие приложения на сайте включены.
async function publicPages() {
  const fixed = [
    { name: 'главная', href: '/' },
    { name: 'вход', href: '/login' },
    { name: 'лента новостей', href: '/news' },
    { name: 'лента с поиском', href: '/news?q=бал' },
    { name: 'нет такой страницы', href: '/net-takogo-adresa' },
  ]
  let fromMap = []
  try {
    const robots = await (await fetch(`${BASE}/robots.txt`)).text()
    const maps = [...robots.matchAll(/^Sitemap:\s*(\S+)/gim)].map((m) => m[1])
    for (const url of maps) {
      const res = await fetch(url)
      if (!res.ok) continue
      const paths = [...(await res.text()).matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname)
      fromMap.push(...paths)
    }
  } catch {
    console.error('robots.txt или карта сайтов не ответили — проверяются только фиксированные адреса')
  }
  fromMap = [...new Set(fromMap)].filter((p) => !fixed.some((f) => f.href === p)).slice(0, 12)
  if (!fromMap.length) console.error('карта сайтов не дала адресов — карточка материала не проверена')
  return [...fixed, ...fromMap.map((p) => ({ name: `карта:${p.slice(1, 20)}`, href: p }))]
}

// Экраны под входом. Их в карте сайтов нет по определению (§7: приватные пути закрыты от индекса),
// поэтому они выписаны здесь. Правка материала (/news/admin/items/<id>) отдельно не проверяется:
// её отдаёт тот же шаблон item.php, что и создание (задача 18), — различаются только значения в
// полях и кнопка удаления, а раскладка идентична. Один настоящий адрес правки человек открывает
// руками на шаге 11 этой задачи.
const ADMIN_PAGES = [
  { name: 'сводка ядра', href: '/admin' },
  { name: 'приложения', href: '/admin/apps' },
  { name: 'новости: сводка', href: '/news/admin' },
  { name: 'новости: материалы', href: '/news/admin/items' },
  { name: 'новости: поиск по тексту', href: '/news/admin/items?q=бал' },
  { name: 'новости: вкладка черновиков', href: '/news/admin/items?status=DRAFT' },
  { name: 'новости: форма', href: '/news/admin/items/new' },
  { name: 'новости: рубрики', href: '/news/admin/categories' },
  { name: 'новости: теги', href: '/news/admin/tags' },
]

// Chrome поднимается отдельным процессом и открывает порт отладки не мгновенно: адрес сокета
// берётся из его же списка целей, с попытками первые тридцать секунд.
async function findWsUrl() {
  for (let i = 0; i < 60; i++) {
    try {
      const list = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json())
      const page = list.find((t) => t.type === 'page')
      if (page) return page.webSocketDebuggerUrl
    } catch {
      // Chrome ещё поднимается
    }
    await sleep(500)
  }
  throw new Error('Chrome не поднялся на порту ' + PORT)
}

// Обёртка над сокетом протокола отладки: счётчик сообщений, таблица ожидающих ответов и ожидание
// события загрузки страницы. Встроенный в Node `WebSocket` подходит как есть — пакет не нужен.
function connect(url) {
  const ws = new WebSocket(url)
  let id = 0
  const pending = new Map()
  const loadWaiters = []
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data)
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id)
      pending.delete(msg.id)
      if (msg.error) reject(new Error(JSON.stringify(msg.error)))
      else resolve(msg.result)
    } else if (msg.method === 'Page.loadEventFired') {
      loadWaiters.forEach((fn) => fn())
    }
  })
  const open = new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }))
  return {
    ws,
    open,
    send(method, params = {}) {
      return new Promise((resolve, reject) => {
        const n = ++id
        pending.set(n, { resolve, reject })
        ws.send(JSON.stringify({ id: n, method, params }))
      })
    },
    // Пятнадцать секунд — потолок ожидания загрузки одного экрана: дальше проба мерит то, что
    // успело построиться, и страница попадает в отчёт как есть.
    onLoad() {
      return new Promise((resolve) => {
        loadWaiters.push(resolve)
        setTimeout(resolve, 15000)
      })
    },
  }
}

// Проба измеряет то, что посетитель действительно видит. Выражение — строка: его исполняет
// Runtime.evaluate внутри страницы, а не Node.
//
// Свёрнутый <details> Chrome раскладывает, но не показывает (content-visibility: hidden): его
// строки оказываются за экраном там, где прокладки нет, и проба посчитала бы их лишними. Перед
// замером блоки раскрываются, оставшиеся свёрнутые не учитываются.
//
// Переполнение — это не только scrollWidth документа: элемент правее экрана внутри
// [overflow-x: auto] прокручивается и не ломает страницу, поэтому такие места считаются
// правильными (таблицы справочников в админке живут именно так, §8.5).
const PROBE = `(() => {
  const vw = window.innerWidth
  const scrollable = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const ox = getComputedStyle(p).overflowX
      if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') return true
    }
    return false
  }
  const hiddenInClosedDetails = (el) => {
    const d = el.closest('details')
    return d !== null && !d.open
  }
  const bad = []
  document.querySelectorAll('body *').forEach((el) => {
    const r = el.getBoundingClientRect()
    if (!r.width) return
    if (r.right > vw + 1.5) {
      if (scrollable(el) || hiddenInClosedDetails(el)) return
      const cls = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\\s+/)[0] : ''
      bad.push({ sel: el.tagName.toLowerCase() + cls, right: Math.round(r.right), w: Math.round(r.width) })
    }
  })
  const uniq = []
  bad.sort((a, b) => b.right - a.right).forEach((b) => {
    if (uniq.length < 6 && !uniq.some((u) => u.sel === b.sel)) uniq.push(b)
  })
  return {
    scrollWidth: document.documentElement.scrollWidth,
    vw,
    url: location.pathname,
    title: document.title,
    offenders: uniq,
  }
})()`

// Имя файла под скриншот: адреса из карты сайтов дают имена вида «карта:/news/osennij-bal», а
// двоеточие и слэш Windows в имени не принимает — прогон на машине разработчика падал бы на
// записи файла, уже отмерив все экраны. Отчёт (report.json) при этом пишет полные имена.
function safeName(name) {
  return name.replace(/[^a-zA-Zа-яА-Я0-9._-]+/g, '_')
}

async function main() {
  mkdirSync(OUT, { recursive: true })
  // Кука получается до подъёма Chrome: она кладётся в браузер командой протокола, а не ответом
  // сервера, поэтому её значение должно быть известно к моменту подключения.
  const cookie = await loginCookie()
  const proc = spawn(
    CHROME,
    [
      '--headless=new',
      `--remote-debugging-port=${PORT}`,
      '--remote-allow-origins=*',
      `--user-data-dir=${join(OUT, 'profile')}`,
      '--no-first-run',
      '--disable-gpu',
      'about:blank',
    ],
    { stdio: 'ignore' },
  )
  const report = []
  const ws = connect(await findWsUrl())
  try {
    await ws.open
    await ws.send('Page.enable')
    await ws.send('Network.enable')

    // Кука кладётся в браузер командой протокола, а не ответом сервера, поэтому поставить её
    // можно в любой момент прогона. Ставится она ПОСЛЕ замера публичных адресов: под живой
    // сессией маршрут /login редиректит в /admin, и форма входа — экран, с которого начинается
    // путь любого посетителя, — осталась бы без замера раскладки вообще.
    const setSessionCookie = () =>
      ws.send('Network.setCookie', {
        name: SESSION_COOKIE,
        value: cookie,
        domain: new URL(BASE).hostname,
        path: '/',
        httpOnly: true,
        sameSite: 'Lax',
      })

    const measure = async (list) => {
      for (const vp of VIEWPORTS) {
        // Метка mobile меняет то, как Chrome применяет media-запросы: на узких экранах часть
        // раскладок живёт именно в них, и их надо просить как мобильный вьюпорт, а не сжимать
        // десктопный.
        await ws.send('Emulation.setDeviceMetricsOverride', {
          width: vp.w,
          height: vp.h,
          deviceScaleFactor: 1,
          mobile: vp.w < 500,
        })
        for (const item of list) {
          const wait = ws.onLoad()
          await ws.send('Page.navigate', { url: BASE + item.href })
          await wait
          await sleep(900)
          await ws.send('Runtime.evaluate', {
            expression: `document.querySelectorAll('details').forEach((d) => { d.open = true })`,
          })
          await sleep(200)
          const { result } = await ws.send('Runtime.evaluate', { expression: PROBE, returnByValue: true })
          const row = { page: item.name, href: item.href, vp: vp.name, ...result.value }
          if (SHOTS && (vp.name === '360' || vp.name === '1440')) {
            const shot = await ws.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
            writeFileSync(join(OUT, `${safeName(item.name)}-${vp.name}.png`), Buffer.from(shot.data, 'base64'))
          }
          report.push(row)
          const ok = row.offenders.length === 0 && row.scrollWidth <= row.vw + 1
          const redirected = row.url !== item.href.split('?')[0] ? ` → ${row.url}` : ''
          console.log(
            `${ok ? 'OK  ' : 'FAIL'} ${item.name.padEnd(24)} ${vp.name.padStart(4)}  scroll=${row.scrollWidth}${redirected}  ` +
              row.offenders.map((o) => `${o.sel}→${o.right}`).join(' | ').slice(0, 200),
          )
        }
      }
    }

    // RESP_PAGES задаёт список вручную — так проверяют адреса с id из базы (форма правки
    // материала, заявка): их скрипт сам выдумать не может. В этом режиме кука ставится сразу,
    // потому что список явно просит экраны под входом.
    const explicit = process.env.RESP_PAGES ? JSON.parse(process.env.RESP_PAGES) : null
    if (explicit !== null) {
      if (!Array.isArray(explicit) || explicit.length === 0) {
        throw new Error('RESP_PAGES задан, но пуст: проверять нечего')
      }
      if (cookie) await setSessionCookie()
      await measure(explicit)
    } else {
      const guest = await publicPages()
      if (!guest.length) throw new Error('публичных адресов нет: карта сайтов пуста — проверять нечего')
      await measure(guest)
      if (cookie) {
        await setSessionCookie()
        await measure(ADMIN_PAGES)
      }
    }
  } finally {
    // Chrome обязан умереть даже при падении прогона: живой процесс держит порт и профиль, и
    // следующий запуск начнётся с ошибки.
    ws.ws.close()
    proc.kill()
  }

  writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, 2))
  const fails = report.filter((r) => r.offenders.length || r.scrollWidth > Number(r.vp) + 1)
  console.log(`\nОтчёт: ${join(OUT, 'report.json')}`)
  console.log(`Проверено ${report.length} комбинаций, проблем: ${fails.length}`)
  // Код возврата нужен gate-скрипту и человеку: надпись «проблем: 3» сама себя не остановит.
  if (fails.length) process.exitCode = 1
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
