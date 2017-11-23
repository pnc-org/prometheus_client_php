<?php

namespace Prometheus\Storage;


use Prometheus\MetricFamilySamples;

class InMemory implements Adapter
{
    private $storeToFile;
    private $stateFile;

    private $counters = array();
    private $gauges = array();
    private $histograms = array();

    public function __construct($storeToFile=false) {
        $this->storeToFile = $storeToFile;

        if (!$this->storeToFile) {
          return;
        }

        $this->stateFile = __DIR__ . "/state.json";

        if (!file_exists($this->stateFile)) {
            return;
        }

        $data = json_decode(file_get_contents($this->stateFile), true);
        $this->counters = $data['counters'];
        $this->gauges = $data['gauges'];
        $this->histograms = $data['histograms'];
    }

    public function __destruct() {
        if ($this->storeToFile) {
            $data = json_encode(array(
                "counters" => $this->counters,
                "gauges" => $this->gauges,
                "histograms" => $this->histograms
            ));
            file_put_contents($this->stateFile, $data);
        }
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function collect()
    {
        $metrics = $this->internalCollect($this->counters);
        $metrics = array_merge($metrics, $this->internalCollect($this->gauges));
        $metrics = array_merge($metrics, $this->collectHistograms());
        return $metrics;
    }

    public function flushMemory()
    {
        $this->counters = array();
        $this->gauges = array();
        $this->histograms = array();
    }

    private function collectHistograms()
    {
        $histograms = array();
        foreach ($this->histograms as $histogram) {
            $metaData = $histogram['meta'];
            $data = array(
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'buckets' => $metaData['buckets']
            );

            // Add the Inf bucket so we can compute it later on
            $data['buckets'][] = '+Inf';

            $histogramBuckets = array();
            foreach ($histogram['samples'] as $key => $value) {
                $parts = explode(':', $key);
                $labelValues = $parts[2];
                $bucket = $parts[3];
                // Key by labelValues
                $histogramBuckets[$labelValues][$bucket] = $value;
            }

            // Compute all buckets
            $labels = array_keys($histogramBuckets);
            sort($labels);
            foreach ($labels as $labelValues) {
                $acc = 0;
                $decodedLabelValues = json_decode($labelValues);
                foreach ($data['buckets'] as $bucket) {
                    $bucket = (string)$bucket;
                    if (!isset($histogramBuckets[$labelValues][$bucket])) {
                        $data['samples'][] = array(
                            'name' => $metaData['name'] . '_bucket',
                            'labelNames' => array('le'),
                            'labelValues' => array_merge($decodedLabelValues, array($bucket)),
                            'value' => $acc
                        );
                    } else {
                        $acc += $histogramBuckets[$labelValues][$bucket];
                        $data['samples'][] = array(
                            'name' => $metaData['name'] . '_' . 'bucket',
                            'labelNames' => array('le'),
                            'labelValues' => array_merge($decodedLabelValues, array($bucket)),
                            'value' => $acc
                        );
                    }
                }

                // Add the count
                $data['samples'][] = array(
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => array(),
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc
                );

                // Add the sum
                $data['samples'][] = array(
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => array(),
                    'labelValues' => $decodedLabelValues,
                    'value' => $histogramBuckets[$labelValues]['sum']
                );

            }
            $histograms[] = new MetricFamilySamples($data);
        }
        return $histograms;
    }

    private function internalCollect(array $metrics)
    {
        $result = array();
        foreach ($metrics as $metric) {
            $metaData = $metric['meta'];
            $data = array(
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
            );
            foreach ($metric['samples'] as $key => $value) {
                $parts = explode(':', $key);
                $labelValues = $parts[2];
                $data['samples'][] = array(
                    'name' => $metaData['name'],
                    'labelNames' => array(),
                    'labelValues' => json_decode($labelValues),
                    'value' => $value
                );
            }
            $this->sortSamples($data['samples']);
            $result[] = new MetricFamilySamples($data);
        }
        return $result;
    }

    public function updateHistogram(array $data)
    {
        // Initialize the sum
        $metaKey = $this->metaKey($data);
        if (array_key_exists($metaKey, $this->histograms) === false) {
            $this->histograms[$metaKey] = array(
                'meta' => $this->metaData($data),
                'samples' => array()
            );
        }
        $sumKey = $this->histogramBucketValueKey($data, 'sum');
        if (array_key_exists($sumKey, $this->histograms[$metaKey]['samples']) === false) {
            $this->histograms[$metaKey]['samples'][$sumKey] = 0;
        }

        $this->histograms[$metaKey]['samples'][$sumKey] += $data['value'];


        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        $bucketKey = $this->histogramBucketValueKey($data, $bucketToIncrease);
        if (array_key_exists($bucketKey, $this->histograms[$metaKey]['samples']) === false) {
            $this->histograms[$metaKey]['samples'][$bucketKey] = 0;
        }
        $this->histograms[$metaKey]['samples'][$bucketKey] += 1;
    }

    public function updateGauge(array $data)
    {
        $metaKey = $this->metaKey($data);
        $valueKey = $this->valueKey($data);
        if (array_key_exists($metaKey, $this->gauges) === false) {
            $this->gauges[$metaKey] = array(
                'meta' => $this->metaData($data),
                'samples' => array()
            );
        }
        if (array_key_exists($valueKey, $this->gauges[$metaKey]['samples']) === false) {
            $this->gauges[$metaKey]['samples'][$valueKey] = 0;
        }
        if ($data['command'] === Adapter::COMMAND_SET) {
            $this->gauges[$metaKey]['samples'][$valueKey] = $data['value'];
        } else {
            $this->gauges[$metaKey]['samples'][$valueKey] += $data['value'];
        }
    }

    public function updateCounter(array $data)
    {
        $metaKey = $this->metaKey($data);
        $valueKey = $this->valueKey($data);
        if (array_key_exists($metaKey, $this->counters) === false) {
            $this->counters[$metaKey] = array(
                'meta' => $this->metaData($data),
                'samples' => array()
            );
        }
        if (array_key_exists($valueKey, $this->counters[$metaKey]['samples']) === false) {
            $this->counters[$metaKey]['samples'][$valueKey] = 0;
        }
        if ($data['command'] === Adapter::COMMAND_SET) {
            $this->counters[$metaKey]['samples'][$valueKey] = 0;
        } else {
            $this->counters[$metaKey]['samples'][$valueKey] += $data['value'];
        }
    }

    /**
     * @param array $data
     *
     * @param       $bucket
     *
     * @return string
     */
    private function histogramBucketValueKey(array $data, $bucket)
    {
        return implode(':', array(
            $data['type'],
            $data['name'],
            json_encode($data['labelValues']),
            $bucket
        ));
    }

    /**
     * @param array $data
     *
     * @return string
     */
    private function metaKey(array $data)
    {
        return implode(':', array($data['type'], $data['name'], 'meta'));
    }

    /**
     * @param array $data
     *
     * @return string
     */
    private function valueKey(array $data)
    {
        return implode(':',
            array($data['type'], $data['name'], json_encode($data['labelValues']), 'value'));
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function metaData(array $data)
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value']);
        unset($metricsMetaData['command']);
        unset($metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    private function sortSamples(array &$samples)
    {
        usort($samples, function ($a, $b) {
            return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
        });
    }
}
