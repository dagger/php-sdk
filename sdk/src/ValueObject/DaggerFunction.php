<?php

declare(strict_types=1);

namespace Dagger\ValueObject;

use Dagger\Agent;
use Dagger\Attribute;
use Dagger\Changeset;
use Dagger\Container;
use Dagger\Exception\RegistrationError\Invalid;
use Dagger\Exception\RegistrationError\MissingAttribute;
use Dagger\Exception\UnsupportedType;
use Dagger\LLM;
use Dagger\Service;
use Dagger\Workspace;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/** @internal Value Object used for methods to expose to Dagger. */
final readonly class DaggerFunction
{
    /** @param Argument[] $arguments */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $arguments,
        public ListOfType|Type $returnType,
        public bool $withCheck = false,
        public bool $withGenerator = false,
        public bool $withUp = false,
        public bool $withAgent = false,
    ) {
        if ($this->withCheck) {
            $this->validateCheck();
        }
        if ($this->withGenerator) {
            $this->validateGenerator();
        }
        if ($this->withUp) {
            $this->validateUp();
        }
        if ($this->withAgent) {
            $this->validateAgent();
        }
    }

    public function isConstructor(): bool
    {
        return $this->name === '';
    }

    /**
     * @throws RuntimeException
     * - if missing DaggerFunction Attribute
     * - if any parameter types are unsupported
     * - if the return type is unsupported
     */
    public static function fromReflection(ReflectionMethod $method): self
    {
        if ($method->getAttributes(Attribute\DaggerFunction::class) === []) {
            throw new RuntimeException(sprintf(
                'Method "%s" is not considered a dagger function without the %s attribute',
                $method->getName(),
                Attribute\DaggerFunction::class
            ));
        }

        $description = (current($method
            ->getAttributes(Attribute\Doc::class)) ?: null)
            ?->newInstance()
            ?->description;

        $parameters = array_map(
            fn($p) => Argument::fromReflection($p),
            $method->getParameters(),
        );

        return $method->isConstructor()
            ? new self(
                name: '',
                description: null,
                arguments: $parameters,
                returnType: new Type($method->getDeclaringClass()->name),
                withCheck: $method->getAttributes(Attribute\Check::class) !== [],
                withGenerator: $method->getAttributes(Attribute\Generate::class) !== [],
                withUp: $method->getAttributes(Attribute\Up::class) !== [],
                withAgent: $method->getAttributes(Attribute\Agent::class) !== [],
            )
            : new self(
                name: $method->name,
                description: $description,
                arguments: $parameters,
                returnType: self::getReturnType($method),
                withCheck: $method->getAttributes(Attribute\Check::class) !== [],
                withGenerator: $method->getAttributes(Attribute\Generate::class) !== [],
                withUp: $method->getAttributes(Attribute\Up::class) !== [],
                withAgent: $method->getAttributes(Attribute\Agent::class) !== [],
            );
    }

    private static function getReturnType(
        ReflectionMethod $method
    ): ListOfType|Type {
        $type = $method->getReturnType() ?? throw new RuntimeException(sprintf(
            'DaggerFunction "%s" cannot be supported without a return type',
            $method->name,
        ));

        if (!($type instanceof ReflectionNamedType)) {
            throw new UnsupportedType(
                'The PHP SDK only supports named types and nullable named types',
            );
        }

        if ($type->getName() === 'array') {
            $attribute = (current($method
                ->getAttributes(Attribute\ReturnsListOfType::class)) ?: null)
                ?->newInstance()
            ?? throw MissingAttribute::returnsListOfType($method->getName());

            return ListOfType::fromReflection($type, $attribute);
        }

        return Type::fromReflection($type);
    }

    private function validateCheck(): void
    {
        foreach ($this->arguments as $arg) {
            if ($arg->isRequired() && !($arg->type instanceof Type && Workspace::class === $arg->type->name)) {
                throw Invalid::checkTakesNoArgs(
                    $this->name,
                    $arg->name,
                );
            }
        }

        if (
            'void' !== $this->returnType->name
            && Container::class !== $this->returnType->name
        ) {
            throw Invalid::checkReturnsContainerOrVoid(
                $this->name,
                $this->returnType->name,
            );
        }
    }

    private function validateGenerator(): void
    {
        foreach ($this->arguments as $arg) {
            if ($arg->isRequired() && !($arg->type instanceof Type && Workspace::class === $arg->type->name)) {
                throw Invalid::generatorRequiresOnlyWorkspace(
                    $this->name,
                    $arg->name,
                );
            }
        }

        $returnType = $this->returnType instanceof Type ? $this->returnType->name : 'array';
        if (Changeset::class !== $returnType || $this->returnType->nullable) {
            throw Invalid::generatorReturnsChangeset(
                $this->name,
                $returnType,
            );
        }
    }

    private function validateUp(): void
    {
        foreach ($this->arguments as $arg) {
            if ($arg->isRequired() && !($arg->type instanceof Type && Workspace::class === $arg->type->name)) {
                throw Invalid::upRequiresOnlyWorkspace(
                    $this->name,
                    $arg->name,
                );
            }
        }

        $returnType = $this->returnType instanceof Type ? $this->returnType->name : 'array';
        if (Service::class !== $returnType || $this->returnType->nullable) {
            throw Invalid::upReturnsService(
                $this->name,
                $returnType,
            );
        }
    }

    private function validateAgent(): void
    {
        $returnType = $this->returnType instanceof Type ? $this->returnType->name : 'array';
        if (LLM::class !== $returnType || $this->returnType->nullable) {
            throw Invalid::agentReturnsLLM(
                $this->name,
                $returnType,
            );
        }

        $llms = 0;
        $bases = 0;
        foreach ($this->arguments as $arg) {
            $type = $arg->type instanceof Type ? $arg->type->name : 'array';
            $llms += LLM::class === $type ? 1 : 0;
            if (!$arg->isRequired() || Workspace::class === $type || Agent::class === $type) {
                continue;
            }
            if (LLM::class === $type && ++$bases === 1) {
                continue;
            }
            throw Invalid::agentRequiresArgument(
                $this->name,
                $arg->name,
            );
        }

        if ($bases !== 1 || $llms !== 1) {
            throw Invalid::agentTakesOneBase($this->name);
        }
    }
}
