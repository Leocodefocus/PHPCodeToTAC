<?php

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;

class ThreeAddressCodeGenerator extends NodeVisitorAbstract
{
    private $labelCounter = 1;
    private $tempVarCounter = 1;
    private $threeAddressCode = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\If_) {
            $condition = $this->handleExpr($node->cond);
            $ifLabel = $this->createLabel();
            $elseLabel = $this->createLabel();
            $endLabel = $this->createLabel();

            $this->threeAddressCode[] = "if " . $condition . " goto " . $ifLabel['start'];
            $this->threeAddressCode[] = "goto " . $elseLabel['start'];
            $this->threeAddressCode[] = $ifLabel['start'] . ":";

            $this->traverse($node->stmts);

            $this->threeAddressCode[] = "goto " . $endLabel['start'];
            $this->threeAddressCode[] = $elseLabel['start'] . ":";

            if ($node->else !== null) {
                $this->traverse($node->else->stmts);
            }

            $this->threeAddressCode[] = $endLabel['start'] . ":";
        } elseif ($node instanceof Node\Stmt\While_) {
            $condition = $this->handleExpr($node->cond);
            $startLabel = $this->createLabel();
            $endLabel = $this->createLabel();

            $this->threeAddressCode[] = $startLabel['start'] . ":";
            $this->threeAddressCode[] = "if " . $condition . " goto " . $endLabel['start'];

            $this->traverse($node->stmts);

            $this->threeAddressCode[] = "goto " . $startLabel['start'];
            $this->threeAddressCode[] = $endLabel['start'] . ":";
        } elseif ($node instanceof Node\Stmt\For_) {
            if ($node->init !== null) {
                $this->traverse($node->init);
            }

            $startLabel = $this->createLabel();
            $endLabel = $this->createLabel();

            $this->threeAddressCode[] = $startLabel['start'] . ":";

            if ($node->cond !== null) {
                $condition = $this->handleExpr($node->cond);
                $this->threeAddressCode[] = "if " . $condition . " goto " . $endLabel['start'];
            }

            $this->traverse($node->stmts);

            if ($node->loop !== null) {
                $this->traverse($node->loop);
            }

            $this->threeAddressCode[] = "goto " . $startLabel['start'];
            $this->threeAddressCode[] = $endLabel['start'] . ":";
        } elseif ($node instanceof Node\Stmt\Foreach_) {
            $valueVar = $this->handleExpr($node->valueVar);
            $arrayVar = $this->handleExpr($node->expr);

            $this->threeAddressCode[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $node->getAttribute('startLabel');
            $this->threeAddressCode[] = $node->getAttribute('endLabel') . ":";
        } elseif ($node instanceof Node\Stmt\Class_) {
            $className = $this->handleName($node->name);
            $this->threeAddressCode[] = "class " . $className;

            $this->traverse($node->stmts);

            $this->threeAddressCode[] = "end_class " . $className;
        } elseif ($node instanceof Node\Stmt\Function_) {
            $functionName = $this->handleName($node->name);
            $this->threeAddressCode[] = "function " . $functionName;

            $this->traverse($node->stmts);

            $this->threeAddressCode[] = "end_function " . $functionName;
        } elseif ($node instanceof Node\Stmt\Expression) {
            $this->threeAddressCode[] = $this->handleExpr($node->expr);
        } elseif ($node instanceof Node\Expr\BinaryOp) {
            $left = $this->handleExpr($node->left);
            $right = $this->handleExpr($node->right);
            $operator = $this->getOperator($node);
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = " . $left . " " . $operator . " " . $right;
        } elseif ($node instanceof Node\Expr\UnaryOp) {
            $expr = $this->handleExpr($node->expr);
            $operator = $this->getOperator($node);
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = " . $operator . " " . $expr;
        } elseif ($node instanceof Node\Expr\Variable) {
            $this->threeAddressCode[] = $node->name;
        } elseif ($node instanceof Node\Expr\New_) {
            $this->threeAddressCode[] = 'new ' . $node->class->toString();
        } elseif ($node instanceof Node\Name) {
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = " . $node->toString() . "\n";
        } elseif ($node instanceof Node\Identifier) {
            $this->threeAddressCode[] = $node->name;
        } elseif ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                $propertyName = $prop->name->name;
                $this->threeAddressCode[] = "property $" . $propertyName;
            }
        } elseif ($node instanceof Node\Stmt\PropertyProperty) {
            $propertyName = $node->name->name;
            $this->threeAddressCode[] = "property $" . $propertyName;
        } elseif ($node instanceof Node\Scalar\LNumber) {
            $value = $node->value;
            $this->threeAddressCode[] = "const " . $value;
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $methodName = $node->name->name;
            $this->threeAddressCode[] = "method " . $methodName;

            $this->traverse($node->stmts);

            $this->threeAddressCode[] = "end_method " . $methodName;
        } elseif ($node instanceof Node\Stmt\Return_) {
            $returnValue = $this->handleExpr($node->expr);
            $this->threeAddressCode[] = 'return ' . $returnValue;
        } elseif ($node instanceof Node\Param) {
            $paramName = $node->var->name;
            $this->threeAddressCode[] = "param $" . $paramName;
        } else {
            throw new Exception("Unsupported node type: " . get_class($node));
        }
    }

    public function getThreeAddressCode()
    {
        return $this->threeAddressCode;
    }

    private function handleExpr(Node\Expr $expr)
    {
        if ($expr instanceof Node\Expr\BinaryOp) {
            $left = $this->handleExpr($expr->left);
            $right = $this->handleExpr($expr->right);
            $operator = $this->getOperator($expr);
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = " . $left . " " . $operator . " " . $right;
            return $tempVar;
        } elseif ($expr instanceof Node\Expr\UnaryOp) {
            $subExpr = $this->handleExpr($expr->expr);
            $operator = $this->getOperator($expr);
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = " . $operator . " " . $subExpr;
            return $tempVar;
        } elseif ($expr instanceof Node\Expr\Variable) {
            return $expr->name;
        } elseif ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        } elseif ($expr instanceof Node\Expr\New_) {
            return 'new ' . $expr->class->toString();
        } elseif ($expr instanceof Node\Scalar\LNumber) {
            return $expr->value;
        } elseif ($expr instanceof Node\Scalar\DNumber) {
            return $expr->value;
        } elseif ($expr instanceof Node\Scalar\String_) {
            return "'" . $expr->value . "'";
        } elseif ($expr instanceof Node\Expr\Array_) {
            $arrayItems = [];
            foreach ($expr->items as $item) {
                $value = $item->value !== null ? $this->handleExpr($item->value) : 'null';
                $arrayItems[] = $value;
            }
            return "[" . implode(", ", $arrayItems) . "]";
        } elseif ($expr instanceof Node\Expr\ErrorSuppress) {
            $subExpr = $this->handleExpr($expr->expr);
            return "@" . $subExpr;
        } else {
            throw new Exception("Unsupported expression type: " . get_class($expr));
        }
    }

    private function getOperator(Node $node)
    {
        if ($node instanceof Node\Expr\BinaryOp\Plus) {
            return '+';
        } elseif ($node instanceof Node\Expr\BinaryOp\Minus) {
            return '-';
        } elseif ($node instanceof Node\Expr\BinaryOp\Mul) {
            return '*';
        } elseif ($node instanceof Node\Expr\BinaryOp\Div) {
            return '/';
        } elseif ($node instanceof Node\Expr\BinaryOp\Concat) {
            return '.';
        } else {
            throw new Exception("Unsupported operator type: " . get_class($node));
        }
    }

    private function createLabel()
    {
        $label = '_L' . $this->labelCounter++;
        return [
            'start' => $label,
            'end' => $label . '_end',
        ];
    }

    private function createTempVar()
    {
        $tempVar = '$t' . $this->tempVarCounter++;
        return $tempVar;
    }

    private function handleName(Node\Name $name)
    {
        if ($name instanceof Node\Name) {
            return $name->toString();
        }
        return implode('\\', $name->parts);
    }
}

$file_path = '/home/leo/phpAVT-new/code_examples.php';

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

try {
    $code = file_get_contents($file_path);
    $stmts = $parser->parse($code);

    $threeAddressCodeGenerator = new ThreeAddressCodeGenerator();
    $traverser = new NodeTraverser;
    $traverser->addVisitor($threeAddressCodeGenerator);
    $traverser->traverse($stmts);

    echo implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
} catch (Error $e) {
    echo 'Parse Error: ', $e->getMessage();
}
