<?php
require __DIR__.'/../src/WatchRecurrence.php';
use SLiMS\Plugins\Inventory\WatchRecurrence as R;
function expect($actual,$expected,string $label): void { if($actual!==$expected)throw new RuntimeException($label.' '.json_encode($actual));echo "ok   $label\n"; }
expect(R::at('2024-01-31','monthly',1),'2024-02-29','leap-year month end');
expect(R::at('2024-01-31','monthly',2),'2024-03-31','anchor restored after short month');
expect(R::at('2023-01-31','monthly',1),'2023-02-28','non-leap month end');
expect(R::at('2024-02-29','annual',1),'2025-02-28','annual leap day');
expect(R::at('2024-02-29','annual',4),'2028-02-29','annual anchor retained');
expect(R::at('2024-08-31','semiannual',1),'2025-02-28','six-month recurrence');
expect(R::at('2024-01-31','quarterly',1),'2024-04-30','quarterly recurrence');
expect(iterator_to_array(R::dates('2024-01-31','monthly','2024-02-01','2024-04-30')),['2024-02-29','2024-03-31','2024-04-30'],'range catches missed occurrences');
expect(iterator_to_array(R::dates('2024-01-01','weekly','2024-01-09','2024-01-23')),['2024-01-15','2024-01-22'],'weekly bounded range');
foreach(['2024-02-30','bad','2024-2-01']as$date){try{R::date($date);throw new LogicException('Accepted invalid date');}catch(RuntimeException $e){echo "ok   invalid date rejected\n";}}
