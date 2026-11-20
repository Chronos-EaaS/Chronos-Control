"""System-specific container bootstrap, sysbench connection and SQL client."""

from dataclasses import dataclass

DATABASE = "sbtest"
USER = "sbtest"
PASSWORD = "local-synthetic-only"
HOST = "study-db"


@dataclass(frozen=True)
class DatabaseAdapter:
    system: str
    default_image: str
    driver: str
    port: int
    environment: tuple[str, ...]
    version_query: str
    settings_query: str

    def connection_args(self):
        prefix = f"--{self.driver}-"
        options = {
            "host": HOST, "port": self.port, "user": USER,
            "password": PASSWORD, "db": DATABASE,
        }
        if self.driver == "mysql":
            options["ignore-errors"] = "none"
            options["storage-engine"] = "innodb"
        return [f"--db-driver={self.driver}", *(f"{prefix}{key}={value}" for key, value in options.items())]

    def sql(self, query):
        raise NotImplementedError


class PostgreSQL(DatabaseAdapter):
    def sql(self, query):
        return [
            "env", f"PGPASSWORD={PASSWORD}", "psql", "-X", "--no-password",
            "-h", "127.0.0.1", "-p", str(self.port), "-U", USER, "-d", DATABASE,
            "-v", "ON_ERROR_STOP=1", "-At", "-F", "\t", "-c", query,
        ]


class MariaDB(DatabaseAdapter):
    def sql(self, query):
        return [
            "env", f"MYSQL_PWD={PASSWORD}", "mariadb", "--no-defaults",
            "--protocol=tcp", "--host=127.0.0.1", f"--port={self.port}",
            f"--user={USER}", f"--database={DATABASE}", "--batch", "--raw",
            "--skip-column-names", "--quick", "--execute", query,
        ]


ADAPTERS = {
    "postgresql": PostgreSQL(
        "postgresql", "postgres:17-bookworm", "pgsql", 5432,
        (f"POSTGRES_DB={DATABASE}", f"POSTGRES_USER={USER}", f"POSTGRES_PASSWORD={PASSWORD}",
         "POSTGRES_INITDB_ARGS=--locale=C --encoding=UTF8"),
        "SELECT version();",
        "SELECT name, setting, COALESCE(unit, '') FROM pg_settings WHERE name IN "
        "('block_size', 'default_transaction_isolation', 'effective_cache_size', 'fsync', "
        "'jit', 'lc_collate', 'max_connections', 'shared_buffers', 'synchronous_commit', "
        "'wal_level', 'work_mem') ORDER BY name;",
    ),
    "mariadb": MariaDB(
        "mariadb", "mariadb:11.4", "mysql", 3306,
        (f"MARIADB_DATABASE={DATABASE}", f"MARIADB_USER={USER}", f"MARIADB_PASSWORD={PASSWORD}",
         "MARIADB_ROOT_PASSWORD=local-synthetic-root-only", "MARIADB_ROOT_HOST=localhost"),
        "SELECT VERSION();",
        "SHOW VARIABLES WHERE Variable_name IN "
        "('autocommit', 'character_set_server', 'collation_server', 'innodb_buffer_pool_size', "
        "'innodb_default_row_format', 'innodb_flush_log_at_trx_commit', 'innodb_page_size', "
        "'max_connections', 'sql_mode', 'tx_isolation');",
    ),
}
