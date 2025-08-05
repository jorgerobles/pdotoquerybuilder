<?php

class TypeInferenceEngine
{
    private array $useStatements = [];
    private array $classProperties = [];
    private array $variableTypes = [];
    private ?string $currentClass = null;
    private ?string $currentNamespace = null;

    private bool $allowShortNameMatching = false;

    public function __construct(bool $allowShortNameMatching = false)
    {
        $this->allowShortNameMatching = $allowShortNameMatching;
    }

    private function typesMatch(string $inferredType, string $targetType): bool
    {
        // Normalize both types
        $inferredType = ltrim($inferredType, '\\');
        $targetType = ltrim($targetType, '\\');

        // Direct match (always preferred)
        if ($inferredType === $targetType) {
            return true;
        }

        // Only do short name matching if explicitly enabled
        if ($this->allowShortNameMatching) {
            $inferredParts = explode('\\', $inferredType);
            $targetParts = explode('\\', $targetType);
            return end($inferredParts) === end($targetParts);
        }

        return false;
    }

    public function matchesTargetClass(string $inferredType, string $targetClass): bool
    {
        return $this->typesMatch($inferredType, $targetClass);
    }

    public function reset(): void
    {
        $this->useStatements = [];
        $this->classProperties = [];
        $this->variableTypes = [];
        $this->currentClass = null;
        $this->currentNamespace = null;
    }

    public function setCurrentNamespace(?string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    public function setCurrentClass(?string $className): void
    {
        $this->currentClass = $className;
    }

    public function addUseStatement(string $alias, string $fullClassName): void
    {
        $this->useStatements[$alias] = $fullClassName;
    }

    public function addClassProperty(string $propertyName, ?string $type, ?string $docType = null): void
    {
        if (!$this->currentClass) return;

        $resolvedType = $this->resolveType($type ?? $docType);
        if ($resolvedType) {
            $this->classProperties[$this->currentClass][$propertyName] = $resolvedType;
        }
    }

    public function addVariableType(string $variableName, string $type): void
    {
        $resolvedType = $this->resolveType($type);
        if ($resolvedType) {
            $this->variableTypes[$variableName] = $resolvedType;
        }
    }

    public function getPropertyType(string $propertyName): ?string
    {
        if ($this->currentClass && isset($this->classProperties[$this->currentClass][$propertyName])) {
            return $this->classProperties[$this->currentClass][$propertyName];
        }
        return null;
    }

    public function getVariableType(string $variableName): ?string
    {
        return $this->variableTypes[$variableName] ?? null;
    }

    public function resolveType(?string $type): ?string
    {
        if (!$type) return null;

        // Remove array notation and nullable indicators
        $type = ltrim($type, '?');
        $type = rtrim($type, '[]');
        $type = trim($type);

        if (empty($type) || in_array($type, ['mixed', 'void', 'null'])) {
            return null;
        }

        // Built-in types
        if (in_array($type, ['string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'resource'])) {
            return null;
        }

        // Already fully qualified
        if (strpos($type, '\\') === 0) {
            return ltrim($type, '\\');
        }

        // Check use statements
        if (isset($this->useStatements[$type])) {
            return $this->useStatements[$type];
        }

        // Check for namespaced class in use statements
        $parts = explode('\\', $type);
        $firstPart = $parts[0];
        if (isset($this->useStatements[$firstPart])) {
            $parts[0] = $this->useStatements[$firstPart];
            return implode('\\', $parts);
        }

        // Add current namespace if available
        if ($this->currentNamespace) {
            return $this->currentNamespace . '\\' . $type;
        }

        return $type;
    }


    public function getUseStatements(): array
    {
        return $this->useStatements;
    }

    public function getCurrentClass(): ?string
    {
        return $this->currentClass;
    }

    public function getCurrentNamespace(): ?string
    {
        return $this->currentNamespace;
    }

    public function getClassProperties(): array
    {
        return $this->classProperties;
    }

    public function getVariableTypes(): array
    {
        return $this->variableTypes;
    }
}