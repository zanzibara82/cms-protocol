-- Озвучка вопросов 26–30 теста на уровень: раньше аудирование было текстом без записи.
-- Файлы лежат в public/assets/audio/learn и названы по номеру вопроса, поэтому обновление
-- находит строки по sort, а не по сгенерированному при установке id.
UPDATE tbl_learn_test_question q
JOIN tbl_learn_test t ON t.id = q.test_id
SET q.audio_url = CASE q.sort
    WHEN 26 THEN '/assets/audio/learn/placement-26.mp3'
    WHEN 27 THEN '/assets/audio/learn/placement-27.mp3'
    WHEN 28 THEN '/assets/audio/learn/placement-28.mp3'
    WHEN 29 THEN '/assets/audio/learn/placement-29.mp3'
    WHEN 30 THEN '/assets/audio/learn/placement-30.mp3'
    ELSE q.audio_url
END
WHERE t.kind = 'PLACEMENT' AND q.sort BETWEEN 26 AND 30;
