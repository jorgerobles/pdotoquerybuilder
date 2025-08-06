<?php

namespace JDR\Rector\MethodsToTraits\Tests;

class TestingHelper
{
    public static function proxy(object $instance): object
    {
        return new class($instance) {
            private object $instance;

            public function __construct($instance)
            {
                $this->instance = $instance;
            }

            public function __call(string $name, array $arguments): mixed
            {
                return \Closure::bind(fn() => $this->{$name}(...$arguments), $this->instance, $this->instance)();
            }
        };
    }
}