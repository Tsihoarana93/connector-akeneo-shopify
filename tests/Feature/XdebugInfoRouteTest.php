<?php

namespace Tests\Feature;

use Tests\TestCase;

class XdebugInfoRouteTest extends TestCase
{
    public function test_the_xdebug_info_route_is_not_exposed_outside_the_local_environment(): void
    {
        $this->assertFalse($this->app->isLocal());

        $this->get('/xdebug-info')->assertNotFound();
    }
}
