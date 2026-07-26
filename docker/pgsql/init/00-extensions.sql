-- Runs once, on first initialisation of the data directory, as the superuser.
--
-- Extensions are created here rather than only in a migration because in
-- production the application role is not a superuser and CREATE EXTENSION is a
-- superuser-only operation. The first migration repeats these statements with
-- IF NOT EXISTS so that a database provisioned by other means still works.

CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS citext;

-- The test database is created by the same superuser session so that the Pest
-- suite runs against a database with the identical extension set. Testing
-- against SQLite is not possible here: generated columns, tstzrange exclusion
-- constraints, tsvector and declarative partitioning have no equivalent.
SELECT 'CREATE DATABASE promo_hub_testing OWNER ' || current_user
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'promo_hub_testing') \gexec

\connect promo_hub_testing

CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS citext;
