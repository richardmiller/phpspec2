<?php

namespace PHPSpec2\Prophet;

use ReflectionMethod;
use ReflectionProperty;

use PHPSpec2\Matcher\MatchersCollection;

use PHPSpec2\Exception\Prophet\ProphetException;
use PHPSpec2\Exception\Prophet\MethodNotFoundException;
use PHPSpec2\Exception\Prophet\PropertyNotFoundException;

class ObjectProphet implements ProphetInterface
{
    private $subject;
    private $matchers;
    private $resolver;

    public function __construct($subject = null, MatchersCollection $matchers,
                                ArgumentsResolver $resolver)
    {
        $this->subject  = $subject;
        $this->matchers = $matchers;
        $this->resolver = $resolver;
    }

    public function isAnInstanceOf($class, array $constructorArguments = array())
    {
        $this->subject = new LazyObject($class, $this->resolver->resolve($constructorArguments));
    }

    public function instantiatedWith()
    {
        if (null === $this->subject) {
            throw new ProphetException('Specify object type first.');
        }

        if (!$this->subject instanceof LazyObject) {
            throw new ProphetException('Object is already initialized.');
        }

        $this->subject->setConstructorArguments($this->resolver->resolve(func_get_args()));
    }

    public function should()
    {
        return new Verification\Positive($this->getProphetSubject(), $this->matchers, $this->resolver);
    }

    public function shouldNot()
    {
        return new Verification\Negative($this->getProphetSubject(), $this->matchers, $this->resolver);
    }

    public function callOnProphetSubject($method, array $arguments = array())
    {
        if (null === $this->getProphetSubject()) {
            throw new ProphetException(sprintf(
                'Call to a member function <value>%s()</value> on a non-object.',
                $method
            ));
        }

        // resolve arguments
        $arguments = $this->resolver->resolve($arguments);

        // if subject is an instance with provided method - call it and stub the result
        if ($this->isSubjectMethodAccessible($method)) {
            $returnValue = call_user_func_array(array($this->getProphetSubject(), $method), $arguments);

            return new static($returnValue, $this->matchers, $this->resolver);
        }

        throw new MethodNotFoundException($this->getProphetSubject(), $method);
    }

    public function setToProphetSubject($property, $value = null)
    {
        $value = $this->resolver->resolve($value);

        if ($this->isSubjectPropertyAccessible($property, true)) {
            return $this->getProphetSubject()->$property = $value;
        }

        throw new PropertyNotFoundException($this->getProphetSubject(), $property);
    }

    public function getFromProphetSubject($property)
    {
        if ($this->isSubjectPropertyAccessible($property)) {
            $returnValue = $this->getProphetSubject()->$property;

            return new static($returnValue, $this->matchers, $this->resolver);
        }

        throw new PropertyNotFoundException($this->getProphetSubject(), $property);
    }

    public function getProphetSubject()
    {
        if (is_object($this->subject) && $this->subject instanceof LazySubjectInterface) {
            $this->subject = $this->subject->getInstance();
        }

        return $this->subject;
    }

    public function getProphetMatchers()
    {
        return $this->matchers;
    }

    public function getProphetResolver()
    {
        return $this->resolver;
    }

    public function __call($method, array $arguments = array())
    {
        // if user calls function with should prefix - call matcher
        if (preg_match('/^(should(?:Not|))(.+)$/', $method, $matches)) {
            $matcherName = lcfirst($matches[2]);
            if ('should' === $matches[1]) {
                return call_user_func_array(array($this->should(), $matcherName), $arguments);
            }

            return call_user_func_array(array($this->shouldNot(), $matcherName), $arguments);
        }

        return $this->callOnProphetSubject($method, $arguments);
    }

    public function __set($property, $value = null)
    {
        return $this->setToProphetSubject($property, $value);
    }

    public function __get($property)
    {
        return $this->getFromProphetSubject($property);
    }

    private function isSubjectMethodAccessible($method)
    {
        if (!is_object($this->getProphetSubject())) {
            return false;
        }

        if (method_exists($this->getProphetSubject(), '__call')) {
            return true;
        }

        if (!method_exists($this->getProphetSubject(), $method)) {
            return false;
        }

        $methodReflection = new ReflectionMethod($this->getProphetSubject(), $method);

        return $methodReflection->isPublic();
    }

    private function isSubjectPropertyAccessible($property, $withValue = false)
    {
        if (!is_object($this->getProphetSubject())) {
            return false;
        }

        if (method_exists($this->getProphetSubject(), $withValue ? '__set' : '__get')) {
            return true;
        }

        if (!property_exists($this->getProphetSubject(), $property)) {
            return false;
        }

        $propertyReflection = new ReflectionProperty($this->getProphetSubject(), $property);

        return $propertyReflection->isPublic();
    }
}
