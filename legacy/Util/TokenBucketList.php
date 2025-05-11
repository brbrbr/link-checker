<?php

namespace Blc\Util;
/**
 * This class implements a variant of the popular token bucket algorithm.
 */
class TokenBucketList
{
    const MICROSECONDS_PER_SECOND = 1000000; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

    /** @var int How many buckets we can manage, in total. */
    private $maxBuckets = 200; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

    private $buckets = array();

    /**
     * @param float $capacity
     * @param float $fillTime
     * @param float $minInterval
     */
    public function __construct(
        /** @var float How many tokens each bucket can hold. */
        private $capacity,
        /** @var  float How long it takes to completely fill a bucket (in seconds). */
        private $fillTime,
        /** @var float Minimum interval between taking tokens from a bucket (in seconds).  */
        private $minTakeInterval = 0
    )
    {
    }

    /**
     * Take one token from a bucket.
     * This method will block until a token becomes available.
     *
     * @param string $bucketName
     */
	 //phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function takeToken($bucketName)
    {
        $this->createIfNotExists($bucketName);
        $this->waitForToken($bucketName);

        --$this->buckets[ $bucketName ]['tokens'];
        $this->buckets[ $bucketName ]['lastTokenTakenAt'] = microtime(true);
    }

    /**
     * Wait until at a token is available.
     *
     * @param string $name Bucket name.
     */
	//phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    private function waitForToken($name)
    {
        $now                = microtime(true);
        $timeSinceLastToken = $now - $this->buckets[ $name ]['lastTokenTakenAt'];
        $intervalWait       = max($this->minTakeInterval - $timeSinceLastToken, 0);
        $requiredTokens     = max(1 - $this->buckets[ $name ]['tokens'], 0);
        $refillWait         = $requiredTokens / $this->getFillRate();
        $totalWait          = round(max($intervalWait, $refillWait) * self::MICROSECONDS_PER_SECOND, 0);

        if ($totalWait > 0) {
            global $blclog;
            $blclog->debug("Waiting for token $totalWait");
            usleep(intval($totalWait));
        }

        $this->refillBucket($name);
        return;
    }

    /**
     * Create a bucket if it doesn't exist yet.
     *
     * @param $name
     */
	//phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    private function createIfNotExists($name)
    {
        if (! isset($this->buckets[ $name ])) {
            $this->buckets[ $name ] = array(
                'tokens'           => $this->capacity,
                'lastRefill'       => microtime(true),
                'lastTokenTakenAt' => 0,
            );
        }
        // Make sure we don't exceed $maxBuckets.
        $this->cleanup();
    }

    /**
     * Calculate how quickly each bucket should be refilled.
     *
     * @return float Fill rate in tokens per second.
     */
	//phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    private function getFillRate()
    {
        return $this->capacity / $this->fillTime;
    }

    /**
     * Refill a bucket with fresh tokens.
     *
     * @param $name
     */
	//phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    private function refillBucket($name)
    {
        $now = microtime(true);

        $timeSinceRefill                   = $now - $this->buckets[ $name ]['lastRefill'];
        $this->buckets[ $name ]['tokens'] += $timeSinceRefill * $this->getFillRate();

        if ($this->buckets[ $name ]['tokens'] > $this->capacity) {
            $this->buckets[ $name ]['tokens'] = $this->capacity;
        }

        $this->buckets[ $name ]['lastRefill'] = $now;
    }

    /**
     * Keep the number of active buckets within the $this->maxBuckets limit.
     */
    private function cleanup()
    {
        if ($this->maxBuckets > 0) {
            // Very simplistic implementation - just discard the oldest buckets.
            while (count($this->buckets) > $this->maxBuckets) {
                array_shift($this->buckets);
            }
        }
    }
}
