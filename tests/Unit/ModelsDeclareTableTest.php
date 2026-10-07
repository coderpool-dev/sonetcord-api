<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Каждая модель объявляет $table. Без него Laravel на каждом запросе вычисляет имя таблицы
 * склонением имени класса — это строит правила doctrine/inflector (~4–5% CPU запроса).
 */
class ModelsDeclareTableTest extends TestCase
{
    public function test_every_model_declares_its_table(): void
    {
        $root = dirname(__DIR__, 2).'/app/Models';
        $missing = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
            $class = str_replace('\\\\', '\\', str_replace(DIRECTORY_SEPARATOR, '\\', $class));
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $property = (new ReflectionClass($class))->getProperty('table');
            if ($property->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Объявите protected $table в моделях: '.implode(', ', $missing));
    }
}
