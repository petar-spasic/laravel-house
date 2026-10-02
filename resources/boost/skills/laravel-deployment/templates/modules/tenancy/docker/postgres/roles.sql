-- tenancy module: the app's login role {{app}}_app owns nothing, is no superuser and cannot bypass row-level security;
-- {{app}} (POSTGRES_USER) owns the databases and every table and only migrates. Idempotent.
-- initdb runs it on a fresh volume, after init-test-db.sql. On an existing volume, and after a DB_PASSWORD change, run
-- it by hand (CLAUDE.md, Hosting). DB_PASSWORD in the postgres service's environment is the app role's password.
\set ON_ERROR_STOP on
\getenv app_password DB_PASSWORD
\if :{?app_password}
\else
\set app_password ''
\endif
SELECT :'app_password' = '' AS no_app_password \gset
\if :no_app_password
DO $$ BEGIN RAISE EXCEPTION 'roles.sql: DB_PASSWORD is unset or empty in the postgres service environment'; END $$;
\endif

SELECT 'CREATE ROLE {{app}}_app' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{{app}}_app') \gexec
SELECT format('ALTER ROLE {{app}}_app WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD %L', :'app_password') \gexec

-- Default privileges live per database and cover only tables {{app}} itself creates: migrations run as exactly {{app}}.
GRANT USAGE ON SCHEMA public TO {{app}}_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {{app}}_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {{app}}_app;
ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {{app}}_app;
ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {{app}}_app;

-- Production has no test database.
SELECT EXISTS (SELECT FROM pg_database WHERE datname = '{{app}}_test') AS has_test_db \gset
\if :has_test_db
\connect {{app}}_test
GRANT USAGE ON SCHEMA public TO {{app}}_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {{app}}_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {{app}}_app;
ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {{app}}_app;
ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {{app}}_app;
\endif
