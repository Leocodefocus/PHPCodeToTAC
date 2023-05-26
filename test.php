<?php
require_once '/home/leo/phpAVT-new/phpsa/vendor/autoload.php';
#https://github.com/nikic/PHP-Parser/blob/4.x/doc/2_Usage_of_basic_components.markdown
#https://github.com/nette/php-generator/tree/master
/*
基于PHP-Parser库，实现从文件中读取PHP代码并解析成抽象语法树，
然后遍历AST，并将其转换成三地址码，然后输出转换结果的功能。
要求能够处理一些列复杂的语法结构，包括但不限于
    赋值表达式，二元和一元运算，函数调用，数组访问，
    方法调用，对象属性访问，实例化新对象，
    类定义，函数定义以及 if-else 结构和循环结构。
*/
require 'vendor/autoload.php';
use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\NodeDumper;
use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract};
class ThreeAddressCodeGenerator extends PhpParser\NodeVisitorAbstract {
    private $tempVarCounter = 0;
    private $threeAddressCode;
    private $labelCounter = 0;
    private $printer;
    private $processedNodes;

    public function __construct(){
        //parent::__construct();
        $this->printer = new PhpParser\PrettyPrinter\Standard();
        $this->threeAddressCode = [];
        $this->processedNodes = new \SplObjectStorage();
    }

    // 创建一个临时变量
    private function createTempVar() {
        return '_t' . $this->tempVarCounter++;
    }
    // 添加一个帮助函数来处理表达式
    private function handleExpr(PhpParser\Node\Expr $expr=null) {
        //$this->processedNodes->attach($expr);
        if ($expr === null) {
            return '';
        }
        if ($expr instanceof PhpParser\Node\Expr\Assign) {
            $varName = $this->handleExpr($expr->var);
            $exprName = $this->handleExpr($expr->expr);
        } elseif ($expr instanceof PhpParser\Node\Expr\Variable) {
            // 处理变量
            return "$" . $expr->name;
        } elseif ($expr instanceof PhpParser\Node\Expr\BinaryOp) {
            // 处理二元运算符
            $left = $this->handleExpr($expr->left);
            $right = $this->handleExpr($expr->right);
            return $left . " " . $this->getOperator($expr) . " " . $right;
        } elseif ($expr instanceof PhpParser\Node\Scalar\String_) {
            // 处理字符串
            return "'" . $expr->value . "'";
        } elseif ($expr instanceof PhpParser\Node\Expr\UnaryOp) {
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $expr->getOperatorSigil() . " " . $this->handleExpr($expr->expr) . "\n";
            // return $tempVar;
            return $expr->getOperatorSigil() . " " . $this->handleExpr($expr->expr) . "\n";
        } elseif ($expr instanceof PhpParser\Node\Expr\FuncCall) {
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            $tempVar = $this->createTempVar();
            $tempVar = $tempVar . " = " . $expr->name . "(" . implode(", ", $argVars) . ")\n";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\MethodCall) {
            $objectVar = $this->handleExpr($expr->var);
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            $tempVar = $this->createTempVar();
            $tempVar = $tempVar . " = call " . $objectVar . "." . $expr->name->name . "(" . implode(", ", $argVars) . ")\n";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\PropertyFetch) {
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $this->handleExpr($expr->var) . "->" . $expr->name->name . "\n";
            // return $tempVar;
            return $this->handleExpr($expr->var) . "->" . $expr->name->name;
        } elseif ($expr instanceof PhpParser\Node\Expr\New_) {
            // 处理新对象的实例化
            return 'new ' . $expr->class->toString();
        } elseif ($expr instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // 处理数组元素获取
            $var = $this->handleExpr($expr->var);
            $dim = $this->handleExpr($expr->dim);
            return $var . '[' . $dim . ']';
        } elseif ($expr instanceof PhpParser\Node\Expr\BooleanNot) {
            // 处理布尔取反操作
            $expr = $this->handleExpr($expr->expr);
            return '!' . $expr;
        } elseif ($expr instanceof PhpParser\Node\Scalar\Encapsed) {
            // 处理字符串插值
            // return implode("", array_map([$this, "handleExpr"], $expr->parts));
            $parts = array_map(function ($part) {
                if ($part instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
                    return $part->value;
                } else {
                    return $this->handleExpr($part);
                }
            }, $expr->parts);
            return implode('', $parts);
        } elseif ($expr instanceof PhpParser\Node\Expr\ClassConstFetch){
            // Assume that $this->handleName can convert a Name node to a string.
            $className = $this->handleName($expr->class);
            return "{$className}::{$expr->name}";
        } elseif ($expr instanceof PhpParser\Node\Expr\ConstFetch){
            return $expr->name->toString(); // 常量的名称
        } elseif ($expr instanceof PhpParser\Node\Expr\Empty_){
            $exprName = $this->handleExpr($expr->expr);
            return "empty($exprName)";
        } elseif ($expr instanceof PhpParser\Node\Expr\ErrorSuppress) {
            // 在 handleExpr() 方法中添加对 PhpParser\Node\Expr\ErrorSuppress 类型的处理
            // 处理 ErrorSuppress 类型的表达式
            $tempVar = $this->createTempVar();
            $tempVar = $tempVar . " = @" . $this->handleExpr($expr->expr) . "\n";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\PostInc) {
            $var = $this->handleExpr($expr->var);
            return $var . '++';
        } elseif ($expr instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            // 处理字符串插值的一部分
            return $expr->value;
        } elseif ($expr instanceof PhpParser\Node\Expr\Array_) {
            $elements = $expr->items;
            $arrayVars = [];
            foreach ($elements as $element) {
                $arrayVars[] = $this->handleExpr($element->value);
            }
            $tempVar = $this->createTempVar();
            $tempVar = $tempVar . " = [" . implode(", ", $arrayVars) . "]";
            return $tempVar;
        } elseif ($expr instanceof PhpParser\Node\Expr\AssignOp\Concat) {
            $var = $this->handleExpr($expr->var);
            $expr = $this->handleExpr($expr->expr);
            $tempVar = $this->createTempVar();
            return $tempVar . " = " . $var . " . " . $expr;
        } elseif ($expr instanceof Node\Scalar\LNumber) {
            return $expr->value;
        } else {
            throw new Exception("Unsupported expression type: " . get_class($expr));
        }
    }
    public function handleNode(PhpParser\Node $node){
        $this->processedNodes->attach($node);
        // if ($node instanceof PhpParser\Node\Param) {
        //     // 处理函数或方法的参数
        //     // 可以根据需要获取参数的名称、默认值等信息
        //     $paramName = $node->var->name;
        //     $defaultValue = $node->default ? $this->handleExpr($node->default) : null;
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将参数名称和默认值添加到三地址码数组
        //     if($defaultValue==null){
        //         $this->threeAddressCode[] = $paramName;
        //     }
        //     $this->threeAddressCode[] = $paramName . ' = ' . $defaultValue;
        // } 
        if ($node instanceof PhpParser\Node\Stmt\If_) {
            // 处理 if 语句
            $condition = $this->handleExpr($node->cond);
            $ifStmts = $this->generateStatements($node->stmts);
            $elseStmts = $node->else !== null ? $this->generateStatements($node->else->stmts) : [];    
            $ifLabel = $this->createLabel();
            $elseLabel = $node->else !== null ? $this->createLabel() : null;
            $endLabel = $this->createLabel();    
            $this->threeAddressCode[] = "if " . $condition . " goto " . $ifLabel['start'];
            $this->threeAddressCode[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']);    
            $this->threeAddressCode[] = $ifLabel['start'] . ":";
            $this->threeAddressCode = array_merge($this->threeAddressCode, $ifStmts);
            $this->threeAddressCode[] = "goto " . $endLabel['start'];    
            if ($elseLabel !== null) {
                $this->threeAddressCode[] = $elseLabel['start'] . ":";
                $this->threeAddressCode = array_merge($this->threeAddressCode, $elseStmts);
                $this->threeAddressCode[] = "goto " . $endLabel['start'];
            }    
            $this->threeAddressCode[] = $endLabel['start'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\ElseIf_) {
            $this->threeAddressCode[] = "if " . $node->cond . " goto " . "Label for elseif branch\n";
            // Recurse into "elseif" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\Else_) {
            $this->threeAddressCode[] = "goto Label for else branch\n";
            // Recurse into "else" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\While_) {
            // 处理 while 循环
            $condition = $this->handleExpr($node->cond);
            $stmts = $this->generateStatements($node->stmts);    
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();    
            $this->threeAddressCode[] = $startLabel['start'] . ":";
            $this->threeAddressCode[] = "if " . $condition . " goto " . $loopLabel['start'];
            $this->threeAddressCode[] = "goto " . $loopLabel['end'];    
            $this->threeAddressCode[] = $loopLabel['start'] . ":";
            $this->threeAddressCode = array_merge($this->threeAddressCode, $stmts);
            $this->threeAddressCode[] = "if " . $condition . " goto " . $startLabel['start'];
            $this->threeAddressCode[] = "goto " . $loopLabel['end'];
            $this->threeAddressCode[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\For_) {
            // 处理 for 循环
            $initStmts = $this->generateStatements($node->init);
            $condition = $this->handleExpr($node->cond);
            $loopStmts = $this->generateStatements($node->loop);
            $stmts = $this->generateStatements($node->stmts);
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();
            $this->threeAddressCode = array_merge($this->threeAddressCode, $initStmts);
            $this->threeAddressCode[] = $startLabel['start'] . ":";
            $this->threeAddressCode[] = "if " . $condition . " goto " . $loopLabel['start'];
            $this->threeAddressCode[] = "goto " . $loopLabel['end'];
            $this->threeAddressCode[] = $loopLabel['start'] . ":";
            $this->threeAddressCode = array_merge($this->threeAddressCode, $stmts);
            $this->threeAddressCode = array_merge($this->threeAddressCode, $loopStmts);
            $this->threeAddressCode[] = "if " . $condition . " goto " . $startLabel['start'];
            $this->threeAddressCode[] = "goto " . $loopLabel['end'];
            $this->threeAddressCode[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Foreach_) {
            // 处理 Foreach 循环
            $valueVar = $this->handleExpr($node->valueVar);
            $arrayVar = $this->handleExpr($node->expr);
            $stmts = $this->generateStatements($node->stmts);
            $loopLabel = $this->createLabel();
            $this->threeAddressCode[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'];
            $this->threeAddressCode[] = $loopLabel['start'] . ":";
            $this->threeAddressCode = array_merge($this->threeAddressCode, $stmts);
            $this->threeAddressCode[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'];
            $this->threeAddressCode[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Class_) {
            // 处理类定义
            $className = $this->handleName($node->name);
            $code[] = "class " . $className . "{";
            $code = array_merge($code, $this->generateStatements($node->stmts));
            $code[] = "}";
            $this->threeAddressCode = array_merge($this->threeAddressCode,$code);
        } elseif ($node instanceof PhpParser\Node\Stmt\Function_) {
            // 处理函数定义
            $functionName = $this->handleName($node->name);
            $code[] = "function " . $functionName;

            $params = [];
            foreach ($node->params as $param) {
                $paramName = $this->handleNode($param);
                $params[] = $paramName;
            }
            $code[] = "( " . implode(", ",$params) . " ){"; 


            $code = array_merge($code, $this->generateStatements($node->stmts));

            $code[] = "}";
            $this->threeAddressCode = array_merge($this->threeAddressCode,$code);//['label' => $functionName, 'code' => $code];
        } elseif ($node instanceof PhpParser\Node\Stmt\Expression) {
            // 处理表达式语句
            $this->threeAddressCode[] = $this->handleExpr($node->expr);
        } 
        // elseif ($node instanceof PhpParser\Node\Expr\BinaryOp) {
        //     // 处理二元运算表达式
        //     $left = $this->handleExpr($node->left);
        //     $right = $this->handleExpr($node->right);
        //     $operator = $this->getOperator($node);
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = " . $left . " " . $operator . " " . $right;
        // } elseif ($node instanceof PhpParser\Node\Expr\UnaryOp) {
        //     // 处理一元运算表达式
        //     $expr = $this->handleExpr($node->expr);
        //     $operator = $this->getOperator($node);
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = " . $operator . " " . $expr;
        // } elseif ($node instanceof PhpParser\Node\Expr\Variable) {
        //     // 处理变量
        //     $this->threeAddressCode[] = "$" . $node->name;
        // } elseif ($node instanceof PhpParser\Node\Expr\New_) {
        //     // 处理新对象的实例化
        //     $this->threeAddressCode[] = 'new ' . $node->class->toString();
        // } elseif ($node instanceof PhpParser\Node\Name) {
        //     // 处理名称节点
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = " . $node->toString() . "\n";
        // } elseif ($node instanceof PhpParser\Node\Identifier) {
        //     $this->threeAddressCode[] = $node->name;
        // } 
         
        // elseif($node instanceof PhpParser\Node\Stmt\PropertyProperty) {
        //     // 处理属性定义
        //     $propertyName = $node->name->name;
        //     $defaultValue = $node->default !== null ? $this->handleExpr($node->default) : null;
        //     $this->threeAddressCode[] = "var $" . $propertyName . " = " . $defaultValue;
        // } elseif ($node instanceof PhpParser\Node\Scalar\LNumber) {
        //     // 处理整数常量
        //     $value = $node->value;
        //     $this->threeAddressCode[] = "const " . $value;
        // } 
        //  elseif ($node instanceof PhpParser\Node\Expr\ArrayDimFetch) {
        //     // 处理数组元素访问
        //     // 可以获取数组变量和索引表达式等信息
            
        //     $arrayVar = $this->handleExpr($node->var);
        //     $indexExpr = $this->handleExpr($node->dim);
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将数组元素访问语句添加到三地址码数组
        //     $this->threeAddressCode[] = $arrayVar . ' = ' . $arrayVar . '[' . $indexExpr . ']';
        // } 
        // elseif ($node instanceof PhpParser\Node\Expr\PropertyFetch) {
        //     // 处理属性访问
        //     // 可以获取对象变量和属性名等信息
            
        //     $objectVar = $this->handleExpr($node->var);
        //     $propertyName = $node->name;
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将属性访问语句添加到三地址码数组
        //     $this->threeAddressCode[] = $objectVar . ' = ' . $objectVar . '->' . $propertyName;
        // } elseif ($node instanceof PhpParser\Node\Expr\ErrorSuppress) {
        //     // 处理错误抑制表达式
        //     // 可以获取表达式信息
            
        //     $expr = $this->handleExpr($node->expr);
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将错误抑制语句添加到三地址码数组
        //     $this->threeAddressCode[] = "@" . $expr;
        // } elseif ($node instanceof PhpParser\Node\Arg) {
        //     // 处理函数或方法调用的参数
        //     // 可以获取参数的值
            
        //     $argValue = $this->handleExpr($node->value);
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将参数值添加到三地址码数组
        //     $this->threeAddressCode[] = "arg " . $argValue;
        // } elseif ($node instanceof PhpParser\Node\Scalar\String_) {
        //     // 处理字符串节点
        //     // 可以获取字符串的值
            
        //     $stringValue = $node->value;
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将字符串值添加到三地址码数组
        //     $this->threeAddressCode[] = "string " . $stringValue;
        // } elseif ($node instanceof PhpParser\Node\Expr\BooleanNot) {
        //     // 处理布尔取反节点
        //     // 可以获取取反的表达式
            
        //     $expression = $node->expr;
            
        //     // 在这里可以根据需要生成相应的三地址码
            
        //     // 示例：将布尔取反表达式添加到三地址码数组
        //     $this->threeAddressCode[] = "not " . $this->handleExpr($expression);
        // } elseif ($node instanceof PhpParser\Node\Scalar\Encapsed) {
        //     // 处理字符串插值节点
        //     $parts = $node->parts;
        //     $result = [];
        //     foreach ($parts as $part) {
        //         if ($part instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
        //             $result[] = $part->value;
        //         } else {
        //             $result[] = $this->handleExpr($part);
        //         }
        //     }
        //     // 在这里可以根据需要生成相应的三地址码
        //     $this->threeAddressCode[] = implode('', $result);
        // } 
        elseif ($node instanceof PhpParser\Node\Expr\Assign) {
            // 处理赋值语句
            $var = $this->handleExpr($node->var);
            $value = $this->handleExpr($node->expr);
            $this->threeAddressCode[] = $var . " = " . $value;
        } 
         elseif ($node instanceof PhpParser\Node\Expr\MethodCall) {
            // 先处理对象表达式
            $objectVar = $this->handleExpr($node->var);            
            // 然后处理参数列表
            $argVars = [];
            foreach ($node->args as $arg) {
                $argVars[] = $this->handleExpr($arg->value);
            }
            // 进行方法调用
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = call " . $objectVar . "." . $node->name->name . "(" . implode(", ", $argVars) . ")\n";
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall) {
            $name = $node->name->toString();
            $args = [];
            foreach ($node->args as $arg) {
                $args[] = $this->handleExpr($arg->value);
            }
            $tempVar = $this->createTempVar();
            $this->threeAddressCode[] = $tempVar . " = call " . $name . "(" . implode(", ", $args) . ")";
        } elseif ($node instanceof PhpParser\Node\Expr\PostInc) {
            // 处理后自增表达式
            // 可以获取变量信息
            
            $varName = $this->handleExpr($node->var);
            
            // 在这里可以根据需要生成相应的三地址码
            
            // 示例：将后自增语句添加到三地址码数组
            $this->threeAddressCode[] = $varName . ' = ' . $varName . ' + 1';
        }
        // elseif ($node instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
        //     // 处理字符串插值的部分
        //     $this->threeAddressCode[] = $node->value;
        // } elseif ($node instanceof PhpParser\Node\Expr\ConstFetch) {
        //     // 处理常量访问
        //     $constName = $this->handleName($node->name);
        //     $this->threeAddressCode[] = $constName;
        // } elseif ($node instanceof PhpParser\Node\Expr\Array_) {
        //     $elements = $node->items;
        //     $arrayVars = [];
        //     foreach ($elements as $element) {
        //         $arrayVars[] = $this->handleExpr($element->value);
        //     }
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = [" . implode(", ", $arrayVars) . "]";
        // } elseif ($node instanceof PhpParser\Node\Expr\AssignOp\Concat) {
        //     $var = $this->handleExpr($node->var);
        //     $expr = $this->handleExpr($node->expr);
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = " . $var . " . " . $expr;
        // } elseif ($node instanceof PhpParser\Node\Expr\Empty_) {
        //     $expr = $this->handleExpr($node->expr);
        //     $tempVar = $this->createTempVar();
        //     $this->threeAddressCode[] = $tempVar . " = empty(" . $expr . ")";
        //  }
    }
    public function enterNode(PhpParser\Node $node) {
        // if ($this->processedNodes->contains($node)) {
        //     return; // 跳过已处理节点
        // }
        $this->handleNode($node);
    }
    public function getThreeAddressCode() {
        return $this->threeAddressCode;
    }
    public function handleName($name)
    {
        if ($name instanceof PhpParser\Node\Identifier) {
            return $name->name;
        } elseif ($name instanceof PhpParser\Node\Name) {
            return $name->getLast();
        }
        return implode('\\', $name->parts);
    }
    
    public function getOperator($operator) {
        switch (get_class($operator)) {
            case PhpParser\Node\Expr\BinaryOp\Plus::class:
                return '+';
            case PhpParser\Node\Expr\BinaryOp\Minus::class:
                return '-';
            case PhpParser\Node\Expr\BinaryOp\Mul::class:
                return '*';
            case PhpParser\Node\Expr\BinaryOp\Div::class:
                return '/';
            // 这里添加更多你需要处理的运算符类型
            case PhpParser\Node\Expr\BinaryOp\Concat::class:
                return '.';
            default:
                throw new Exception("Unsupported operator type: " . get_class($operator));
        }
    }
    private function generateStatements(array $stmts) {
        $code = [];
        $result = [];
        foreach ($stmts as $stmt) {
            $this->processedNodes->attach($stmt);
            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                // 处理 if 语句
                $condition = $this->handleExpr($stmt->cond);
                $ifStmts = $this->generateStatements($stmt->stmts);
                $elseStmts = $stmt->else !== null ? $this->generateStatements($stmt->else->stmts) : [];
                $ifLabel = $this->createLabel();
                $elseLabel = $stmt->else !== null ? $this->createLabel() : null;
                $endLabel = $this->createLabel();
                $code[] = "if " . $condition . " goto " . $ifLabel['start'];
                $code[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']);
                $code[] = $ifLabel['start'] . ":";
                $code = array_merge($code, $ifStmts);
                $code[] = "goto " . $endLabel['start'];
                if ($elseLabel !== null) {
                    $code[] = $elseLabel['start'] . ":";
                    $code = array_merge($code, $elseStmts);
                    $code[] = "goto " . $endLabel['start'];
                }
                $code[] = $endLabel['start'] . ":";
                // $result[] = ['label' => $ifLabel['start'], 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                // 处理 while 循环
                $condition = $this->handleExpr($stmt->cond);
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code[] = $startLabel['start'] . ":";
                $code[] = "if " . $condition . " goto " . $loopLabel['start'];
                $code[] = "goto " . $loopLabel['end'];
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "if " . $condition . " goto " . $startLabel['start'];
                $code[] = "goto " . $loopLabel['end'];
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                // 处理 for 循环
                $initStmts = $stmt->init !== null ? $this->generateStatements([$stmt->init]) : [];
                $condition = $stmt->cond !== null ? $this->handleExpr($stmt->cond) : null;
                $loopStmts = $stmt->loop !== null ? $this->generateStatements([$stmt->loop]) : [];
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code = array_merge($code, $initStmts);
                $code[] = $startLabel['start'] . ":";
                if ($condition !== null) {
                    $code[] = "if " . $condition . " goto " . $loopLabel['start'];
                    $code[] = "goto " . $loopLabel['end'];
                } else {
                    $code[] = "goto " . $loopLabel['start'];
                }   
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code = array_merge($code, $loopStmts);
                if ($condition !== null) {
                    $code[] = "if " . $condition . " goto " . $startLabel['start'];
                    $code[] = "goto " . $loopLabel['end'];
                } else {
                    $code[] = "goto " . $startLabel['start'];
                }
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                // 处理 foreach 循环
                $valueVar = $this->handleExpr($stmt->valueVar);
                $arrayVar = $this->handleExpr($stmt->expr);
                $stmts = $this->generateStatements($stmt->stmts);
                $loopLabel = $this->createLabel();
                $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'];  
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "foreach " . $arrayVar . " as " . $valueVar . " goto " . $loopLabel['start'];  
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $loopLabel['start'], 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
                    $argVars = [];
                    foreach ($stmt->args as $arg) {
                        $argVars[] = $this->handleExpr($arg->value);
                    }
                    $tempVar = $this->createTempVar();
                    $code[] = $tempVar . " = call " . $stmt->name->name . "(" . implode(", ", $argVars) . ")\n";
                    // $result[] = ['label' => $tempVar, 'code' => $code];
                    $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                // 处理类定义
                $className = $stmt->name;
                $code[] = "class " . $className . "{";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}\n";
                // $result[] = ['label' => $className, 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                // 处理函数定义
                $functionName = $stmt->name;
                $code[] = "function " . $functionName;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_function " . $functionName;
                // $result[] = ['label' => $functionName, 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                // 处理异常处理
                $code[] = "try";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                foreach ($stmt->catches as $catch) {
                    $code = array_merge($code, $this->generateStatements([$catch]));
                }
                if ($stmt->finally !== null) {
                    $code = array_merge($code, $this->generateStatements([$stmt->finally->stmts]));
                }
                $code[] = "end_try";
                // $result[] = ['label' => 'try_catch', 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Catch_) {
                // 处理 catch
                $code[] = "catch " . $stmt->varType . " as " . $stmt->var->name;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_catch";
                // $result[] = ['label' => $stmt->var->name, 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Finally_) {
                // 处理 finally
                $code[] = "finally";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_finally";
                // $result[] = ['label' => 'finally', 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
                // 处理赋值语句
                $var = $this->handleExpr($stmt->var);
                $expr = $this->handleExpr($stmt->expr);
                $code[] = $var . " = " . $expr;
                // $result[] = ['label' => $var, 'code' => $code];
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                // 处理属性定义
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->name;
                    $defaultValue = $prop->default !== null ? $this->handleExpr($prop->default) : null;
                    $code[] = "var $" . $propertyName . " = " . $defaultValue;
                }
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                // 处理类方法定义
                $methodName = $stmt->name->name;
                $code[] = "function " . $methodName;
                
                $params = [];
                foreach ($stmt->params as $param) {
                    $paramName = $this->handleNode($param);
                    $params[] = $paramName;
                }
                $code[] = "( " . implode(", ",$params) . " ){"; 
    
                $code = array_merge($code, $this->generateStatements($stmt->stmts));
    
                $code[] = "}";
                $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $returnValue = $this->handleExpr($stmt->expr);
                $result[] = 'return ' . $returnValue;
            } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc) {
                // 处理后自增表达式
                // 可以获取变量信息
                
                $varName = $this->handleExpr($stmt->var);
                
                // 在这里可以根据需要生成相应的三地址码
                
                // 示例：将后自增语句添加到三地址码数组
                $result[] = $varName . ' = ' . $varName . ' + 1';
            }
            $code = [];
        }    
        return $result;
    }   
    private function createLabel() {
        $label = '_L' . $this->labelCounter++;
        return [
            'start' => $label,
            'end' => $label . '_end',
        ];
    }    
    private function createLoopLabel() {
        $labelStart = 'L' . $this->tempVarCounter;
        $labelEnd = 'L' . ($this->tempVarCounter + 1);
        $this->tempVarCounter += 2;
        return ['start' => $labelStart, 'end' => $labelEnd];
    }}
$file_path = '/home/leo/phpAVT-new/code_examples.php';
// 1. 创建解析器
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
try {
    // 2. 从文件读取PHP源代码
    $code = file_get_contents($file_path);
    // 3. 解析源代码
    $stmts = $parser->parse($code);
    // 4. 创建ThreeAddressCodeGenerator
    $threeAddressCodeGenerator = new ThreeAddressCodeGenerator();
    // 5. 遍历抽象语法树，生成三地址码
    //$stmts = $threeAddressCodeGenerator->traverse($stmts);
    $traverser = new NodeTraverser;
    $traverser->addVisitor($threeAddressCodeGenerator);
    #$modifiedStmts = 
    $traverser->traverse($stmts);
    // 6. 输出三地址码
    echo implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
    // echo implode("\n",$modifiedStmts);
} catch (Error $e) {
    echo 'Parse Error: ', $e->getMessage();}
?>