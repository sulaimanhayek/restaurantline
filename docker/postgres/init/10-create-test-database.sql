-- The Pest suite runs against PostgreSQL rather than SQLite, because the menu
-- matcher leans on pg_trgm and a test that cannot express the production query
-- is not testing much. This gives it a database of its own, created once when
-- the postgres volume is first initialised.
--
-- If you already have a postgres-data volume from an earlier checkout, this
-- script will not re-run: `docker compose down -v` or create the database by
-- hand with `createdb -U restaurantline restaurantline_testing`.
SELECT 'CREATE DATABASE restaurantline_testing OWNER CURRENT_USER'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'restaurantline_testing')\gexec
