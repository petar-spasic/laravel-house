-- Runs once on a fresh volume (docker-entrypoint-initdb.d). For an existing volume:
--   PGPASSWORD={{app}} createdb -h 127.0.0.1 -p {{db_port}} -U {{app}} {{app}}_test
CREATE DATABASE {{app}}_test;
