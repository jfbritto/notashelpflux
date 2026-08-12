-- O banco de teste nasce junto com o de desenvolvimento, senão a primeira
-- rodada de `php artisan test` falha por banco inexistente e parece defeito
-- do código.
CREATE DATABASE IF NOT EXISTS notas_testing;
GRANT ALL PRIVILEGES ON notas_testing.* TO 'notas'@'%';
FLUSH PRIVILEGES;
