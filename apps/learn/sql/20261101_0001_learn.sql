-- Учебный контур раздела «Курсы»: профиль учёбы, дерево курса, упражнения, тесты,
-- прогресс, XP, серия и статистика. Внешних ключей нет: целостность несут операции,
-- как у меню и конструктора. IF NOT EXISTS обязателен: MySQL фиксирует DDL сразу,
-- поэтому журнал пишется после каждого файла, а не одним оборотом.
CREATE TABLE IF NOT EXISTS tbl_learn_profile (
    user_id VARCHAR(26) NOT NULL,
    onboarding_done TINYINT(1) NOT NULL DEFAULT 0,
    placement_done TINYINT(1) NOT NULL DEFAULT 0,
    self_assessment VARCHAR(20) NOT NULL DEFAULT '',
    goal VARCHAR(20) NOT NULL DEFAULT '',
    daily_minutes INT NOT NULL DEFAULT 0,
    start_level_id VARCHAR(26) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_level (
    id VARCHAR(26) NOT NULL,
    code VARCHAR(12) NOT NULL,
    title VARCHAR(120) NOT NULL,
    sort INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY learn_level_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_course (
    id VARCHAR(26) NOT NULL,
    level_id VARCHAR(26) NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_course_level (level_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_module (
    id VARCHAR(26) NOT NULL,
    course_id VARCHAR(26) NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_module_course (course_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_lesson (
    id VARCHAR(26) NOT NULL,
    module_id VARCHAR(26) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY learn_lesson_slug (slug),
    KEY learn_lesson_module (module_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_objective (
    id VARCHAR(26) NOT NULL,
    lesson_id VARCHAR(26) NOT NULL,
    text VARCHAR(300) NOT NULL,
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_objective_lesson (lesson_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_vocab (
    id VARCHAR(26) NOT NULL,
    lesson_id VARCHAR(26) NOT NULL,
    word VARCHAR(60) NOT NULL,
    translation VARCHAR(120) NOT NULL DEFAULT '',
    ipa VARCHAR(120) NOT NULL DEFAULT '',
    example_en VARCHAR(300) NOT NULL DEFAULT '',
    example_ru VARCHAR(300) NOT NULL DEFAULT '',
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_vocab_lesson (lesson_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_grammar (
    id VARCHAR(26) NOT NULL,
    lesson_id VARCHAR(26) NOT NULL,
    title VARCHAR(120) NOT NULL,
    summary VARCHAR(500) NOT NULL DEFAULT '',
    details TEXT NULL,
    examples MEDIUMTEXT NULL,
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_grammar_lesson (lesson_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_exercise (
    id VARCHAR(26) NOT NULL,
    lesson_id VARCHAR(26) NOT NULL,
    type VARCHAR(32) NOT NULL,
    question VARCHAR(300) NOT NULL,
    options MEDIUMTEXT NULL,
    correct_answer MEDIUMTEXT NOT NULL,
    explanation VARCHAR(500) NOT NULL DEFAULT '',
    difficulty INT NOT NULL DEFAULT 1,
    skill VARCHAR(20) NULL,
    xp_reward INT NOT NULL DEFAULT 5,
    audio_url VARCHAR(300) NULL,
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_exercise_lesson (lesson_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_attempt (
    id VARCHAR(26) NOT NULL,
    user_id VARCHAR(26) NOT NULL,
    exercise_id VARCHAR(26) NOT NULL,
    is_correct TINYINT(1) NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    xp_awarded INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY learn_attempt_user (user_id, exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_test (
    id VARCHAR(26) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    lesson_id VARCHAR(26) NULL,
    module_id VARCHAR(26) NULL,
    title VARCHAR(120) NOT NULL,
    pass_score INT NOT NULL DEFAULT 70,
    status VARCHAR(16) NOT NULL DEFAULT 'PUBLISHED',
    PRIMARY KEY (id),
    UNIQUE KEY learn_test_lesson (lesson_id),
    UNIQUE KEY learn_test_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_test_question (
    id VARCHAR(26) NOT NULL,
    test_id VARCHAR(26) NOT NULL,
    skill VARCHAR(20) NOT NULL,
    text VARCHAR(300) NOT NULL,
    options MEDIUMTEXT NOT NULL,
    correct_option_index INT NOT NULL,
    explanation VARCHAR(500) NOT NULL DEFAULT '',
    audio_url VARCHAR(300) NULL,
    passage_ref VARCHAR(60) NULL,
    sort INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY learn_test_question_test (test_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_test_attempt (
    id VARCHAR(26) NOT NULL,
    user_id VARCHAR(26) NOT NULL,
    test_id VARCHAR(26) NOT NULL,
    score INT NOT NULL,
    vocabulary_score INT NULL,
    grammar_score INT NULL,
    reading_score INT NULL,
    listening_score INT NULL,
    passed TINYINT(1) NOT NULL,
    recommended_level_code VARCHAR(12) NULL,
    recommended_start_module_id VARCHAR(26) NULL,
    answers MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY learn_test_attempt_user (user_id, test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_progress (
    user_id VARCHAR(26) NOT NULL,
    current_level_id VARCHAR(26) NULL,
    completed_lessons MEDIUMTEXT NOT NULL,
    completed_modules MEDIUMTEXT NOT NULL,
    total_study_seconds INT NOT NULL DEFAULT 0,
    current_module_order INT NOT NULL DEFAULT 1,
    current_lesson_order INT NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_xp (
    user_id VARCHAR(26) NOT NULL,
    total INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_streak (
    user_id VARCHAR(26) NOT NULL,
    current_days INT NOT NULL DEFAULT 0,
    longest_days INT NOT NULL DEFAULT 0,
    last_study_at DATE NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_statistics (
    user_id VARCHAR(26) NOT NULL,
    lessons_completed INT NOT NULL DEFAULT 0,
    tests_taken INT NOT NULL DEFAULT 0,
    exercises_done INT NOT NULL DEFAULT 0,
    correct_rate FLOAT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_learn_registration_attempt (
    id VARCHAR(26) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(120) NOT NULL DEFAULT '',
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY learn_registration_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
