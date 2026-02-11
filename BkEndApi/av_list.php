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
$ts=$resp['Time Series (Daily)'] ?? [];
$i=0;
foreach($ts as $date=>$row){ echo $date."\n"; if(++$i==3) break; }
