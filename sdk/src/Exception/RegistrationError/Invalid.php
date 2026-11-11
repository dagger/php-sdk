<?php

declare(strict_types=1);

namespace Dagger\Exception\RegistrationError;

use Dagger\Exception\RegistrationError;

final class Invalid extends \RuntimeException implements RegistrationError
{
    public static function checkTakesNoArgs(
        string $methodName,
        string $argName,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as a check, but requires '$argName'.
            Checks may only require a Workspace; every other argument must be optional.
            TEXT);
    }

    public static function checkReturnsContainerOrVoid(
        string $methodName,
        string $returnType,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as a check, but returns '$returnType'.
            Checks must return Containers or void.
            TEXT);
    }

    public static function generatorRequiresOnlyWorkspace(
        string $methodName,
        string $argName,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as a generator, but requires '$argName'.
            Generators may only require a Workspace; every other argument must be optional.
            TEXT);
    }

    public static function generatorReturnsChangeset(
        string $methodName,
        string $returnType,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as a generator, but returns '$returnType'.
            Generators must return a non-null Changeset.
            TEXT);
    }

    public static function upRequiresOnlyWorkspace(
        string $methodName,
        string $argName,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as up, but requires '$argName'.
            Up functions may only require a Workspace; every other argument must be optional.
            TEXT);
    }

    public static function upReturnsService(
        string $methodName,
        string $returnType,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as up, but returns '$returnType'.
            Up functions must return a non-null Service.
            TEXT);
    }

    public static function agentRequiresArgument(
        string $methodName,
        string $argName,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as an agent, but requires '$argName'.
            Agents may only require their base LLM, a Workspace or an Agent; every other argument must be optional.
            TEXT);
    }

    public static function agentTakesOneBase(string $methodName): self
    {
        return new self(<<<TEXT
            '$methodName' is labeled as an agent, but does not require exactly one LLM.
            Agents must require one non-null LLM, the base they extend, and take no other LLM.
            TEXT);
    }

    public static function agentReturnsLLM(
        string $methodName,
        string $returnType,
    ): self {
        return new self(<<<TEXT
            '$methodName' is labeled as an agent, but returns '$returnType'.
            Agents must return a non-null LLM.
            TEXT);
    }
}
