<?php
namespace Icicle\Tests\Promise;

use Exception;
use Icicle\Loop\Loop;
use Icicle\Promise\Promise;
use Icicle\Tests\TestCase;

/**
 * @requires PHP 5.4
 */
class PromiseSettleTest extends TestCase
{
    public function tearDown()
    {
        Loop::clear();
    }
    
    public function testEmptyArray()
    {
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->identicalTo([]));
        
        Promise::settle([])->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
    
    public function testValuesArray()
    {
        $values = [1, 2, 3];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->callback(function ($result) use ($values) {
            foreach ($result as $key => $promise) {
                if ($promise->getResult() !== $values[$key]) {
                    return false;
                }
            }
            return true;
        }));
        
        Promise::settle($values)->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
    
    public function testFulfilledPromisesArray()
    {
        $promises = [Promise::resolve(1), Promise::resolve(2), Promise::resolve(3)];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->equalTo($promises));
        
        Promise::settle($promises)->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
    
    public function testPendingPromisesArray()
    {
        $promises = [
            Promise::resolve(1)->delay(0.2),
            Promise::resolve(2)->delay(0.3),
            Promise::resolve(3)->delay(0.1)
        ];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->equalTo($promises));
        
        Promise::settle($promises)->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
    
    public function testArrayKeysPreserved()
    {
        $values = ['one' => 1, 'two' => 2, 'three' => 3];
        $promises = [
            'one' => Promise::resolve(1)->delay(0.2),
            'two' => Promise::resolve(2)->delay(0.3),
            'three' => Promise::resolve(3)->delay(0.1)
        ];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->callback(function ($result) use ($promises) {
            ksort($result);
            ksort($promises);
            return array_keys($result) === array_keys($promises);
        }));
        
        Promise::settle($promises)->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
    
    public function testFulfilledWithRejectedInputPromises()
    {
        $exception = new Exception();
        $promises = [
            Promise::resolve(1)->delay(0.1),
            Promise::reject($exception), 
            Promise::resolve(3)
        ];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->equalTo($promises));
        
        Promise::settle($promises)->done($callback, $this->createCallback(0));
        
        Loop::run();
    }
}
