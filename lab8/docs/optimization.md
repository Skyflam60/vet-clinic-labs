# Полные планы выполнения запросов

Вывод `\i /sql/06_optimization.sql` на базе после `05_test_data.sql`: 500 008 приёмов, 150 005 животных, 100 005 владельцев. PostgreSQL 16.15. Время зависит от компьютера и меняется от запуска к запуску; сравнивается соотношение «до» и «после».

План читается снизу вверх: нижняя строка — как база достала строки, верхние — что она с ними сделала потом.

## Запрос 1. История приёмов животного

`GET /api/animals/{id}/appointments` — условие по внешнему ключу `animal_id`. Индекс `idx_appointments_animal_id ON appointments (animal_id)`.

### До
```
                                                           QUERY PLAN                                                            
---------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=9527.65..9527.66 rows=4 width=44) (actual time=42.710..47.292 rows=3 loops=1)
   Sort Key: scheduled_at DESC
   Sort Method: quicksort  Memory: 25kB
   ->  Gather  (cost=1000.00..9527.61 rows=4 width=44) (actual time=14.264..47.277 rows=3 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Parallel Seq Scan on appointments  (cost=0.00..8527.21 rows=2 width=44) (actual time=14.869..33.063 rows=1 loops=3)
               Filter: (animal_id = 1234)
               Rows Removed by Filter: 166668
 Planning Time: 0.109 ms
 Execution Time: 47.319 ms
(11 rows)
```

Три процесса параллельно перебирают таблицу, каждый отбрасывает около 166 тысяч строк, чтобы найти 3 нужные.

### После
```
                                                               QUERY PLAN                                                                
-----------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=20.23..20.24 rows=4 width=44) (actual time=0.065..0.066 rows=3 loops=1)
   Sort Key: scheduled_at DESC
   Sort Method: quicksort  Memory: 25kB
   ->  Bitmap Heap Scan on appointments  (cost=4.45..20.19 rows=4 width=44) (actual time=0.049..0.055 rows=3 loops=1)
         Recheck Cond: (animal_id = 1234)
         Heap Blocks: exact=3
         ->  Bitmap Index Scan on idx_appointments_animal_id  (cost=0.00..4.45 rows=4 width=0) (actual time=0.040..0.040 rows=3 loops=1)
               Index Cond: (animal_id = 1234)
 Planning Time: 0.225 ms
 Execution Time: 0.093 ms
(10 rows)
```

`Bitmap Index Scan` находит номера трёх строк в индексе, `Bitmap Heap Scan` читает только их. Сортировка трёх строк ничего не стоит.

## Запрос 2. Животные владельца

Карточка владельца — условие по внешнему ключу `animals.owner_id`. Индекс `idx_animals_owner_id ON animals (owner_id)`.

### До
```
                                                 QUERY PLAN                                                 
------------------------------------------------------------------------------------------------------------
 Sort  (cost=3580.07..3580.08 rows=2 width=32) (actual time=13.129..13.132 rows=3 loops=1)
   Sort Key: id
   Sort Method: quicksort  Memory: 25kB
   ->  Seq Scan on animals  (cost=0.00..3580.06 rows=2 width=32) (actual time=4.281..13.120 rows=3 loops=1)
         Filter: (owner_id = 777)
         Rows Removed by Filter: 150002
 Planning Time: 0.101 ms
 Execution Time: 13.149 ms
(8 rows)
```

### После
```
                                                            QUERY PLAN                                                             
-----------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=12.27..12.27 rows=2 width=32) (actual time=0.060..0.060 rows=3 loops=1)
   Sort Key: id
   Sort Method: quicksort  Memory: 25kB
   ->  Bitmap Heap Scan on animals  (cost=4.44..12.26 rows=2 width=32) (actual time=0.045..0.050 rows=3 loops=1)
         Recheck Cond: (owner_id = 777)
         Heap Blocks: exact=3
         ->  Bitmap Index Scan on idx_animals_owner_id  (cost=0.00..4.43 rows=2 width=0) (actual time=0.036..0.036 rows=3 loops=1)
               Index Cond: (owner_id = 777)
 Planning Time: 0.232 ms
 Execution Time: 0.083 ms
(10 rows)
```

## Расписание одного врача на день (новый индекс не нужен)

`UNIQUE (doctor_id, scheduled_at)` создал индекс автоматически. Условие идёт по его первому столбцу (`doctor_id`) и затем по диапазону второго, поэтому база сразу находит 16 приёмов врача за день.

```
                                                                                          QUERY PLAN                                                                                           
-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=70.55..70.59 rows=17 width=42) (actual time=0.070..0.071 rows=16 loops=1)
   Sort Key: scheduled_at
   Sort Method: quicksort  Memory: 26kB
   ->  Bitmap Heap Scan on appointments  (cost=4.64..70.20 rows=17 width=42) (actual time=0.047..0.062 rows=16 loops=1)
         Recheck Cond: ((doctor_id = 10) AND (scheduled_at >= '2026-03-02 00:00:00'::timestamp without time zone) AND (scheduled_at < '2026-03-03 00:00:00'::timestamp without time zone))
         Heap Blocks: exact=10
         ->  Bitmap Index Scan on appointments_doctor_time_key  (cost=0.00..4.64 rows=17 width=0) (actual time=0.041..0.041 rows=16 loops=1)
               Index Cond: ((doctor_id = 10) AND (scheduled_at >= '2026-03-02 00:00:00'::timestamp without time zone) AND (scheduled_at < '2026-03-03 00:00:00'::timestamp without time zone))
 Planning Time: 0.107 ms
 Execution Time: 0.085 ms
(10 rows)
```

## Запрос 3. Ближайшие запланированные приёмы, первые 20

`GET /api/appointments?status=scheduled` с сортировкой по времени. Индекс `idx_appointments_status_time ON appointments (status, scheduled_at)`: сначала столбец с равенством, затем столбец диапазона и сортировки.

### До
```
                                                                 QUERY PLAN                                                                  
---------------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=10288.57..10290.91 rows=20 width=46) (actual time=37.369..40.111 rows=20 loops=1)
   ->  Gather Merge  (cost=10288.57..12397.59 rows=18076 width=46) (actual time=37.367..40.107 rows=20 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Sort  (cost=9288.55..9311.14 rows=9038 width=46) (actual time=30.950..30.953 rows=14 loops=3)
               Sort Key: scheduled_at
               Sort Method: top-N heapsort  Memory: 27kB
               Worker 0:  Sort Method: top-N heapsort  Memory: 27kB
               Worker 1:  Sort Method: top-N heapsort  Memory: 26kB
               ->  Parallel Seq Scan on appointments  (cost=0.00..9048.05 rows=9038 width=46) (actual time=14.175..28.387 rows=7270 loops=3)
                     Filter: ((scheduled_at >= '2026-06-01 00:00:00'::timestamp without time zone) AND ((status)::text = 'scheduled'::text))
                     Rows Removed by Filter: 159399
 Planning Time: 0.086 ms
 Execution Time: 40.133 ms
(14 rows)
```

Перебор всей таблицы и отдельная сортировка (`Sort`, `top-N heapsort`), чтобы выбрать 20 самых ранних.

### После
```
                                                                        QUERY PLAN                                                                         
-----------------------------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=0.42..20.30 rows=20 width=46) (actual time=0.043..0.051 rows=20 loops=1)
   ->  Index Scan using idx_appointments_status_time on appointments  (cost=0.42..21562.22 rows=21692 width=46) (actual time=0.042..0.048 rows=20 loops=1)
         Index Cond: (((status)::text = 'scheduled'::text) AND (scheduled_at >= '2026-06-01 00:00:00'::timestamp without time zone))
 Planning Time: 0.277 ms
 Execution Time: 0.068 ms
(5 rows)
```

Шага `Sort` нет: в индексе строки со статусом `scheduled` уже лежат по возрастанию времени. База находит первую подходящую запись, читает 20 подряд и останавливается (`Limit`).

## Запрос 4. Расписание клиники на день

Условие только по `scheduled_at`. Индекс `idx_appointments_scheduled_at ON appointments (scheduled_at)`.

### До
```
                                                                                QUERY PLAN                                                                                
--------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=9531.69..9533.73 rows=817 width=46) (actual time=5.134..5.167 rows=800 loops=1)
   Sort Key: scheduled_at, doctor_id
   Sort Method: quicksort  Memory: 82kB
   ->  Bitmap Heap Scan on appointments  (cost=7244.71..9492.17 rows=817 width=46) (actual time=4.818..4.971 rows=800 loops=1)
         Recheck Cond: ((scheduled_at >= '2026-03-02 00:00:00'::timestamp without time zone) AND (scheduled_at < '2026-03-03 00:00:00'::timestamp without time zone))
         Heap Blocks: exact=11
         ->  Bitmap Index Scan on idx_appointments_status_time  (cost=0.00..7244.50 rows=817 width=0) (actual time=4.799..4.799 rows=800 loops=1)
               Index Cond: ((scheduled_at >= '2026-03-02 00:00:00'::timestamp without time zone) AND (scheduled_at < '2026-03-03 00:00:00'::timestamp without time zone))
 Planning Time: 0.068 ms
 Execution Time: 5.206 ms
(10 rows)
```

К этому моменту уже есть два составных индекса со столбцом `scheduled_at` — `(doctor_id, scheduled_at)` и `(status, scheduled_at)`, — но в обоих он второй. Планировщик выбрал `Bitmap Index Scan` по `(status, scheduled_at)`: индекс отсортирован сначала по статусу, поэтому его пришлось просмотреть целиком (стоимость 7244 из 9492). В пробном прогоне, где запрос 4 выполнялся раньше запроса 3, плана с индексом не было: `Parallel Seq Scan`, около 46 мс.

### После
```
                                                                             QUERY PLAN                                                                             
--------------------------------------------------------------------------------------------------------------------------------------------------------------------
 Incremental Sort  (cost=0.47..69.41 rows=817 width=46) (actual time=0.084..0.657 rows=800 loops=1)
   Sort Key: scheduled_at, doctor_id
   Presorted Key: scheduled_at
   Full-sort Groups: 16  Sort Method: quicksort  Average Memory: 28kB  Peak Memory: 28kB
   ->  Index Scan using idx_appointments_scheduled_at on appointments  (cost=0.42..33.76 rows=817 width=46) (actual time=0.042..0.356 rows=800 loops=1)
         Index Cond: ((scheduled_at >= '2026-03-02 00:00:00'::timestamp without time zone) AND (scheduled_at < '2026-03-03 00:00:00'::timestamp without time zone))
 Planning Time: 0.290 ms
 Execution Time: 0.711 ms
(8 rows)
```

`Index Scan` по новому индексу читает только 800 строк нужного дня в порядке времени. `Incremental Sort` досортировывает по врачу маленькие группы с одинаковым временем.

## Когда индекс не помогает

### Проведённые приёмы по врачам (60% строк)
```
                                                                     QUERY PLAN                                                                     
----------------------------------------------------------------------------------------------------------------------------------------------------
 Finalize GroupAggregate  (cost=10156.93..10169.60 rows=50 width=12) (actual time=81.183..81.402 rows=52 loops=1)
   Group Key: doctor_id
   ->  Gather Merge  (cost=10156.93..10168.60 rows=100 width=12) (actual time=81.170..81.360 rows=152 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Sort  (cost=9156.91..9157.03 rows=50 width=12) (actual time=71.750..71.757 rows=51 loops=3)
               Sort Key: doctor_id
               Sort Method: quicksort  Memory: 27kB
               Worker 0:  Sort Method: quicksort  Memory: 26kB
               Worker 1:  Sort Method: quicksort  Memory: 26kB
               ->  Partial HashAggregate  (cost=9155.00..9155.50 rows=50 width=12) (actual time=71.708..71.716 rows=51 loops=3)
                     Group Key: doctor_id
                     Batches: 1  Memory Usage: 24kB
                     Worker 0:  Batches: 1  Memory Usage: 24kB
                     Worker 1:  Batches: 1  Memory Usage: 24kB
                     ->  Parallel Seq Scan on appointments  (cost=0.00..8527.21 rows=125558 width=4) (actual time=0.017..46.461 rows=99988 loops=3)
                           Filter: ((status)::text = 'done'::text)
                           Rows Removed by Filter: 66681
 Planning Time: 0.241 ms
 Execution Time: 81.454 ms
(20 rows)
```

Индекс `(status, scheduled_at)` есть, но условию подходят 300 тысяч строк из 500 тысяч. Переходить по индексу к каждой строке дороже, чем прочитать таблицу подряд, поэтому выбран `Parallel Seq Scan`.

### Поиск владельца по части телефона
```
                                              QUERY PLAN                                              
------------------------------------------------------------------------------------------------------
 Seq Scan on owners  (cost=0.00..2484.06 rows=10 width=42) (actual time=2.812..24.074 rows=1 loops=1)
   Filter: ((phone)::text ~~ '%0012345%'::text)
   Rows Removed by Filter: 100004
 Planning Time: 0.337 ms
 Execution Time: 24.093 ms
(5 rows)
```

У `phone` есть индекс от `UNIQUE`, но B-tree упорядочен по началу строки и не умеет искать подстроку `'%...%'`. В `07_advanced.sql` для этого создан триграммный индекс GIN (`pg_trgm`), и тот же запрос выполняется за 0,23 мс вместо 21 мс.

## Цена индексов
```
  table_name  |          index_name           |  size   
--------------+-------------------------------+---------
 animals      | animals_pkey                  | 3312 kB
 animals      | idx_animals_owner_id          | 2696 kB
 appointments | appointments_doctor_time_key  | 16 MB
 appointments | appointments_pkey             | 11 MB
 appointments | idx_appointments_animal_id    | 6824 kB
 appointments | idx_appointments_status_time  | 4488 kB
 appointments | idx_appointments_scheduled_at | 3512 kB
(7 rows)

=== Вставка 50 000 приёмов БЕЗ трёх новых индексов ===
BEGIN
DROP INDEX
Timing is on.
INSERT 0 50000
Time: 555.154 ms
Timing is off.
ROLLBACK
=== Вставка 50 000 приёмов С тремя новыми индексами ===
BEGIN
Timing is on.
INSERT 0 50000
Time: 831.667 ms
Timing is off.
ROLLBACK
```

Три новых индекса на `appointments` занимают около 15 МБ. Вставка 50 000 приёмов с ними заняла 832 мс против 555 мс без них: на каждую строку базе приходится обновлять ещё три индекса. Обе вставки отменены `ROLLBACK`.
