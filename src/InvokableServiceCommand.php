<?php

namespace Zenstruck\Console;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * All the benefits of {@see Invokable} but also allows for auto-injection of
 * any service from your Symfony DI container. You can think of it as
 * "Invokable Service Controllers" (with 'controller.service_arguments') but
 * for commands. Instead of a "Request", you inject {@see IO}.
 *
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class InvokableServiceCommand extends Command implements ServiceSubscriberInterface
{
    use Invokable { execute as private invokableExecute; }

    private ContainerInterface $container;

    public static function getSubscribedServices(): array
    {
        return \array_values(
            \array_filter(
                \array_map(
                    static function(\ReflectionParameter $parameter): ?string {
                        if (!$type = $parameter->getType()) {
                            return null;
                        }

                        if (!$type instanceof \ReflectionNamedType) {
                            return null;
                        }

                        $name = $type->getName();

                        if ($type->isBuiltin()) {
                            throw new \LogicException(\sprintf('"%s::__invoke()" cannot accept built-in parameter: $%s (%s).', static::class, $parameter->getName(), $name));
                        }

                        if (\is_a($name, InputInterface::class, true)) {
                            return null;
                        }

                        if (\is_a($name, OutputInterface::class, true)) {
                            return null;
                        }

                        if (\is_a($name, StyleInterface::class, true)) {
                            return null;
                        }

                        return $type->allowsNull() ? '?'.$name : $name;
                    },
                    self::invokeParameters()
                )
            )
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (self::getSubscribedServices() as $serviceId) {
            $optional = 0 === \mb_strpos($serviceId, '?');
            $serviceId = \ltrim($serviceId, '?');

            try {
                $value = $this->container()->get(\ltrim($serviceId, '?'));
            } catch (NotFoundExceptionInterface $e) {
                if (!$optional) {
                    // not optional
                    throw $e;
                }

                // optional
                $value = null;
            }

            $this->addArgumentFactory($serviceId, static fn() => $value);
        }

        return $this->invokableExecute($input, $output);
    }

    /**
     * @required
     */
    public function setInvokeContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    private function container(): ContainerInterface
    {
        if (!isset($this->container)) {
            throw new \LogicException(\sprintf('Container not available in "%s", is this class auto-configured?', static::class));
        }

        return $this->container;
    }
}
