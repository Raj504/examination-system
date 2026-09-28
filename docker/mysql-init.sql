-- Separate database for running the test suite inside the stack
-- (vendor/bin/phpunit -c phpunit.mysql.xml), so tests never touch `exams`.
CREATE DATABASE IF NOT EXISTS exams_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON exams_test.* TO 'exams'@'%';
FLUSH PRIVILEGES;
