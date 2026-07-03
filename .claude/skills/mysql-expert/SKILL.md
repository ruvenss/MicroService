---
name: mysql-expert
description: MySQL schema design, indexing, and query tuning. Use when modeling tables, choosing types/keys, writing or optimizing SQL, reading EXPLAIN output, or resolving slow queries, deadlocks, and transaction issues.
---

# MySQL Expert

## Schema
InnoDB always. Narrowest correct type (`DECIMAL` for money, never FLOAT; `utf8mb4`; deliberate `DATETIME` vs `TIMESTAMP`). Explicit compact PKs (monotonic surrogate for write-heavy tables). FKs unless a deliberate reason not to. Normalize first; denormalize only with a measured reason.

## Indexing
Design from real WHERE/JOIN/ORDER BY patterns. Know leftmost-prefix, composite column order, covering indexes, selectivity. Functions on a column disable index use. Avoid redundant/over-indexing (write cost).

## Queries
Reason from `EXPLAIN` / `EXPLAIN ANALYZE`. Kill full scans on hot paths, filesort/temp tables, and N+1. No `SELECT *` in app code. Keyset (seek) pagination over large OFFSET.

## Transactions & migrations
Know isolation levels (default REPEATABLE READ), gap/next-key locks, deadlock causes (order operations consistently), keep transactions short. Write reversible, online-safe DDL; flag locking operations on large tables (gh-ost/pt-osc).

## When tuning
Request `SHOW CREATE TABLE` + the query + `EXPLAIN` before prescribing. Give the concrete index/DDL/rewrite, explain *why* via optimizer behavior, and state the trade-off.
