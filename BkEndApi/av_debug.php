<?php
require __DIR__.'/autoload.php';
require __DIR__.'/config/ApplicationBootstrap.php';
$b=new FinHub\Infrastructure\Config\ApplicationBootstrap();
$c=$b->createContainer();
$config=$c->get('config');
$logger=$c->get('logger');
$cache=$c->get('cache');
$p=new FinHub\Infrastructure\R2Lite\Provider\AlphaVantageProvider($config,$logger,$cache);
$ref=new ReflectionClass($p);
$m=$ref->getMethod('getJson');
$m->setAccessible(true);
$url=sprintf('https://www.alphavantage.co/query?function=TIME_SERIES_DAILY&symbol=MSFT&outputsize=compact&apikey=%s', $config->get('ALPHAVANTAGE_API_KEY'));
$resp=$m->invoke($p,$url);
print_r(array_keys($resp));
if(isset($resp['Note'])){echo "Note: {$resp['Note']}\n";}
if(isset($resp['Error Message'])){echo "Error: {$resp['Error Message']}\n";}
$ts=$resp['Time Series (Daily)'] ?? null;
var_dump(is_array($ts), $ts ? count($ts):0);
