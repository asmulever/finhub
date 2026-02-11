<?php
require __DIR__.'/autoload.php';
require __DIR__.'/config/ApplicationBootstrap.php';
$b=new FinHub\Infrastructure\Config\ApplicationBootstrap();
$c=$b->createContainer();
$config=$c->get('config');
$logger=$c->get('logger');
$cache=$c->get('cache');
$p=new FinHub\Infrastructure\R2Lite\Provider\AlphaVantageProvider($config,$logger,$cache);
$from=new DateTimeImmutable('-5 days');
$to=new DateTimeImmutable('today');
$rows=$p->fetchDaily('MSFT', $from, $to, 'MERCADO_GLOBAL');
echo "from={$from->format('Y-m-d')} to={$to->format('Y-m-d')} count=".count($rows)."\n";
if($rows){ print_r($rows[0]); }
