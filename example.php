<?php

spl_autoload_register(function($class) {
  $classFilePath = str_replace("\\", "/", $class) . ".php";
  $classFilePath = realpath(__DIR__ . "/src/") . "/" . $classFilePath;
  if (!file_exists($classFilePath)) {
    return false;
  }
  require_once($classFilePath);
  return true;
});

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;


$registry = new CollectorRegistry(new InMemory(/*storeToFile=*/true));

$counter = $registry->registerCounter('test', 'some_counter', 'it increases', array('type'));
$counter->incBy(3, array('blue'));


$gauge = $registry->getOrRegisterGauge('test', 'some_gauge', 'it sets', array('type'));
$gauge->set(2.5, ['blue']);

$histogram = $registry->getOrRegisterHistogram(
    'test', 'some_histogram', 'it observes',
    array('type'),
    array(0.1, 1, 2, 3.5, 4, 5, 6, 7, 8, 9));
$histogram->observe(3.5, array('blue'));

$renderer = new RenderTextFormat();
$result = $renderer->render($registry->getMetricFamilySamples());

print_r($result);
