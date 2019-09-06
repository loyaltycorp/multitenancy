<?php
declare(strict_types=1);

namespace LoyaltyCorp\Multitenancy\Externals\ORM\Listeners;

use Doctrine\ORM\Event\LifecycleEventArgs;
use EoneoPay\Externals\ORM\EntityManager as GenericEntityManager;
use EoneoPay\Utils\CheckDigit;
use EoneoPay\Utils\Interfaces\GeneratorInterface;
use LoyaltyCorp\Multitenancy\Database\Interfaces\HasProviderInterface;
use LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueInterface;
use LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueWithCallbackInterface;
use LoyaltyCorp\Multitenancy\Externals\ORM\EntityManager;
use LoyaltyCorp\Multitenancy\Externals\ORM\Exceptions\UniqueValueNotGeneratedException;
use LoyaltyCorp\Multitenancy\ProviderResolver\Interfaces\ProviderResolverInterface;

/**
 * Doctrine listener that will be applied to entities that have the following interface implemented
 *
 * This listener supports both tenanted and non-tenanted entities
 *
 * @see \LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueInterface
 * @see \LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueWithCallbackInterface
 *
 * Callback is optional, but interface requires entity to declare it
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coupling is required to support both tenanted and non-tenanted entity
 */
final class GenerateUniqueValue
{
    /**
     * The generator.
     *
     * @var \EoneoPay\Utils\Interfaces\GeneratorInterface
     */
    private $generator;

    /**
     * Provider resolver
     *
     * @var \LoyaltyCorp\Multitenancy\ProviderResolver\Interfaces\ProviderResolverInterface
     */
    private $providerResolver;

    /**
     * Initialise the listener
     *
     * @param \EoneoPay\Utils\Interfaces\GeneratorInterface $generator Generator to generate a random string
     * @param \LoyaltyCorp\Multitenancy\ProviderResolver\Interfaces\ProviderResolverInterface $providerResolver Resolver
     */
    public function __construct(GeneratorInterface $generator, ProviderResolverInterface $providerResolver)
    {
        $this->generator = $generator;
        $this->providerResolver = $providerResolver;
    }

    /**
     * Generate unique value for property if required
     *
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $eventArgs Event arguments
     *
     * @return void
     *
     * @throws \EoneoPay\Externals\ORM\Exceptions\RepositoryClassDoesNotImplementInterfaceException If wrong interface
     * @throws \LoyaltyCorp\Multitenancy\Externals\ORM\Exceptions\RepositoryDoesNotImplementInterfaceException Wrong
     * @throws \LoyaltyCorp\Multitenancy\Externals\ORM\Exceptions\UniqueValueNotGeneratedException If no value generated
     */
    public function prePersist(LifecycleEventArgs $eventArgs): void
    {
        $entity = $this->checkEntity($eventArgs->getEntity());

        if (($entity instanceof GenerateUniqueValueInterface) === false) {
            return;
        }

        // Generate value, will throw exception if not possible
        $randomValue = $this->generateValue($entity, $eventArgs);

        $setter = [$entity, \sprintf('set%s', \ucfirst($entity->getGeneratedProperty()))];

        // If setter isn't callable, abort - this is only here for safety since base entity provides __call
        if (\is_callable($setter) === false) {
            return; // @codeCoverageIgnore
        }

        $setter($randomValue);

        if (($entity instanceof GenerateUniqueValueWithCallbackInterface) === true) {
            /**
             * @var \EoneoPay\Externals\ORM\Interfaces\Listeners\GenerateUniqueValueWithCallbackInterface $entity
             *
             * @see https://youtrack.jetbrains.com/issue/WI-37859 - typehint required until PhpStorm recognises === chec
             */
            $entity->getGeneratedPropertyCallback($randomValue);
        }
    }

    /**
     * Determine if this entity requires generation
     *
     * @param mixed $entity The entity to check
     *
     * @return \LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueInterface|null
     */
    private function checkEntity($entity): ?GenerateUniqueValueInterface
    {
        // Entity must be an application entity, implement the correct interface and have the genrator enabled
        return (($entity instanceof HasProviderInterface) === true &&
            ($entity instanceof GenerateUniqueValueInterface) === true &&
            $entity->areGeneratorsEnabled() === true) ? $entity : null;
    }

    /**
     * Generate a random value for this entity
     *
     * @param \LoyaltyCorp\Multitenancy\Externals\Interfaces\ORM\Listeners\GenerateUniqueValueInterface $entity Entity
     * @param \Doctrine\ORM\Event\LifecycleEventArgs $eventArgs Life cycle call back arguments
     *
     * @return string
     *
     * @throws \EoneoPay\Externals\ORM\Exceptions\RepositoryClassDoesNotImplementInterfaceException If wrong interface
     * @throws \LoyaltyCorp\Multitenancy\Externals\ORM\Exceptions\RepositoryDoesNotImplementInterfaceException Wrong
     * @throws \LoyaltyCorp\Multitenancy\Externals\ORM\Exceptions\UniqueValueNotGeneratedException If no value generated
     */
    private function generateValue(GenerateUniqueValueInterface $entity, LifecycleEventArgs $eventArgs): string
    {
        // Get correct entity manager instance for the provided entity
        $entityManager = ($entity instanceof HasProviderInterface) === true ?
            new EntityManager($eventArgs->getEntityManager()) :
            new GenericEntityManager($eventArgs->getEntityManager());
        $repository = $entityManager->getRepository(\get_class($entity));

        // Configure static settings
        $hasCheckDigit = $entity->hasGeneratedPropertyCheckDigit();
        $length = $hasCheckDigit === true ?
            $entity->getGeneratedPropertyLength() - 1 :
            $entity->getGeneratedPropertyLength();
        $property = $entity->getGeneratedProperty();

        // Try 100 times to obtain a unique value
        for ($counter = 0; $counter < 100; $counter++) {
            $randomValue = $this->generator->randomString(
                $length,
                GeneratorInterface::RANDOM_INCLUDE_ALPHA_UPPERCASE |
                GeneratorInterface::RANDOM_INCLUDE_INTEGERS |
                GeneratorInterface::RANDOM_EXCLUDE_SIMILAR
            );

            // If entity requires a check-digit, calculate it
            if ($hasCheckDigit === true) {
                $randomValue = \sprintf('%s%s', $randomValue, (new CheckDigit())->calculate($randomValue));
            }

            // Determine if any records are already using this id
            /**
             * @var \LoyaltyCorp\Multitenancy\Database\Interfaces\HasProviderInterface $entity
             *
             * @see https://youtrack.jetbrains.com/issue/WI-37859 - typehint required until PhpStorm recognises === chec
             */
            $count = ($entity instanceof HasProviderInterface) === true ?
                $repository->count($this->providerResolver->resolve($entity), [$property => $randomValue]) :
                $repository->count([$property => $randomValue]);

            // If records are using this id, try again
            if ($count !== 0) {
                continue;
            }

            return $randomValue;
        }

        // If no return was completed, throw exception
        throw new UniqueValueNotGeneratedException(\sprintf(
            'Unable to generate a unique value for %s on %s',
            $property,
            \get_class($entity)
        ));
    }
}
