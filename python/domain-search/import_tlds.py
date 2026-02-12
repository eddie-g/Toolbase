#!/usr/bin/env python3
"""
Create and populate a TLD table from IANA data.

- Source list: https://data.iana.org/TLD/tlds-alpha-by-domain.txt
- Manager data: https://www.iana.org/domains/root/db

Table created: tlds
Columns: tld, popularity, manager
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from typing import Dict, List, Tuple

import mysql.connector
import requests

TLD_ALPHA_URL = "https://data.iana.org/TLD/tlds-alpha-by-domain.txt"
ROOT_DB_URL = "https://www.iana.org/domains/root/db"
HTTP_HEADERS = {
    # Avoid brotli content to prevent decoding issues in minimal Python envs.
    "Accept-Encoding": "gzip, deflate",
    "User-Agent": "Toolbase-TLD-Importer/1.0",
}


def get_db_connection():
    """Create direct MySQL connection using environment variables."""
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "mysql"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "sail"),
        password=os.getenv("MYSQL_PASSWORD", "password"),
        database=os.getenv("MYSQL_DATABASE", "toolbase"),
    )


def validate_table_name(table_name: str) -> str:
    """Allow only simple SQL identifiers for dynamic table names."""
    if not re.fullmatch(r"[A-Za-z0-9_]+", table_name):
        raise ValueError(
            "Invalid table name. Use only letters, numbers, and underscores."
        )
    return table_name


def fetch_tld_list(timeout: int = 20) -> List[str]:
    """Fetch official IANA TLD alpha list."""
    response = requests.get(TLD_ALPHA_URL, timeout=timeout, headers=HTTP_HEADERS)
    response.raise_for_status()

    tlds: List[str] = []
    for raw_line in response.text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        tlds.append(line.lower())

    return tlds


def _strip_html_tags(value: str) -> str:
    return re.sub(r"<[^>]+>", "", value).strip()


def fetch_managers(timeout: int = 30) -> Dict[str, str]:
    """
    Parse IANA root DB table and return a mapping:
    {"com": "VeriSign Global Registry Services", ...}
    """
    response = requests.get(ROOT_DB_URL, timeout=timeout, headers=HTTP_HEADERS)
    response.raise_for_status()
    html = response.text

    # Each row in the root DB table has 3 tds: domain, type, manager.
    row_pattern = re.compile(r"<tr>(.*?)</tr>", re.IGNORECASE | re.DOTALL)
    td_pattern = re.compile(r"<td[^>]*>(.*?)</td>", re.IGNORECASE | re.DOTALL)

    managers: Dict[str, str] = {}
    href_pattern = re.compile(r'href="/domains/root/db/([a-z0-9-]+)\.html"', re.IGNORECASE)

    for row in row_pattern.findall(html):
        cols = td_pattern.findall(row)
        if len(cols) < 3:
            continue

        domain_match = href_pattern.search(cols[0])
        manager_cell = _strip_html_tags(cols[2])

        if not domain_match:
            continue

        tld = domain_match.group(1).strip().lower()
        if tld:
            managers[tld] = manager_cell

    return managers


def create_table(conn, table_name: str) -> None:
    """Create table if it does not exist."""
    table_name = validate_table_name(table_name)
    cursor = conn.cursor()
    cursor.execute(
        f"""
        CREATE TABLE IF NOT EXISTS `{table_name}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tld VARCHAR(63) NOT NULL,
            popularity INT NULL,
            manager VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tlds_tld_unique (tld)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )
    cursor.close()


def build_rows(tlds: List[str], managers: Dict[str, str], default_popularity: int | None) -> List[Tuple[str, int | None, str | None]]:
    """Build rows for DB insert/upsert."""
    rows: List[Tuple[str, int | None, str | None]] = []
    for tld in tlds:
        rows.append((tld, default_popularity, managers.get(tld)))
    return rows


def upsert_rows(conn, rows: List[Tuple[str, int | None, str | None]], table_name: str) -> int:
    """Insert/update rows in bulk."""
    if not rows:
        return 0

    table_name = validate_table_name(table_name)
    cursor = conn.cursor()
    cursor.executemany(
        f"""
        INSERT INTO `{table_name}` (tld, popularity, manager)
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE
            popularity = VALUES(popularity),
            manager = VALUES(manager),
            updated_at = CURRENT_TIMESTAMP
        """,
        rows,
    )
    affected = cursor.rowcount
    conn.commit()
    cursor.close()
    return affected


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Create and populate tlds table from IANA data",
    )
    parser.add_argument(
        "--popularity",
        type=int,
        default=None,
        help="Default popularity value to assign to all rows (default: NULL)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Fetch and parse data, but do not write to database",
    )
    parser.add_argument(
        "--table",
        default="tlds",
        help="Destination table name (default: tlds)",
    )
    args = parser.parse_args()

    try:
        print("Fetching TLD alpha list from IANA...")
        tlds = fetch_tld_list()
        print(f"Loaded {len(tlds)} TLDs")

        print("Fetching TLD managers from IANA root DB...")
        managers = fetch_managers()
        print(f"Loaded manager data for {len(managers)} TLDs")

        rows = build_rows(tlds, managers, args.popularity)

        if args.dry_run:
            missing_manager = sum(1 for _, _, manager in rows if not manager)
            print(f"Dry run complete. Rows prepared: {len(rows)}")
            print(f"Rows without manager: {missing_manager}")
            return 0

        conn = get_db_connection()
        try:
            create_table(conn, args.table)
            affected = upsert_rows(conn, rows, args.table)
            print(f"Import complete. Prepared rows: {len(rows)}")
            print(f"Rows affected by upsert: {affected}")
        finally:
            conn.close()

        return 0
    except ValueError as exc:
        print(f"Input error: {exc}", file=sys.stderr)
        return 1
    except requests.RequestException as exc:
        print(f"Network error: {exc}", file=sys.stderr)
        return 1
    except mysql.connector.Error as exc:
        print(f"Database error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
