<?php

use Hojabbr\CliProxy\Tests\TestCase;

// Every test gets the container: the driver, the router and the batches all read config,
// and Http::fake() needs an application to fake.
pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->group('live')->in('Live');
