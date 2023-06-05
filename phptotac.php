<?php
require 'vendor/autoload.php';
use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\NodeDumper;
use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract};

class ThreeAddressInstruction {
    public $op;
    public $arg1;
    public $arg2;
    public $result;

    public function __construct($op, $arg1, $arg2, $result) {
        $this->op = $op;
        $this->arg1 = $arg1;
        $this->arg2 = $arg2;
        $this->result = $result;
    }

    public function __toString() {
        return $this->result . " = " . $this->arg1 . " " . $this->op . " " . $this->arg2 . ";";
    }
}


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
        return '$_t' . $this->tempVarCounter++;
    }
    // 添加一个帮助函数来处理表达式
    private function handleExpr(PhpParser\Node\Expr $expr=null) {
        
        if ($expr === null) {
            return [
                "var" => "",
                "tac" => []
            ];
        }
        if ($this->processedNodes->contains($expr)) {
            return [
                "var" => "",
                "tac" => []
            ];
        }
        if ($expr instanceof PhpParser\Node\Expr\Assign) {
            $this->processedNodes->attach($expr);
            $varNameDict = $this->handleExpr($expr->var);
            $exprNameDict = $this->handleExpr($expr->expr);
            //$tac = new ThreeAddressInstruction("", $exprNameDict['var'], "", $varNameDict["var"]);
            //$this->threeAddressCode[] = $tac;
            $tacs = [];
            $tacs = array_merge($tacs,$varNameDict["tac"]);
            $tacs = array_merge($tacs,$exprNameDict["tac"]);
            //$tacs = array_merge($tacs,[$tac]);
            return [
                "var"=>$varNameDict["var"] . " = " . $exprNameDict["var"],
                "tac"=>$tacs
            ];
            //return $varName . " = " . $exprName;
        } elseif ($expr instanceof PhpParser\Node\Expr\Variable) {
            $this->processedNodes->attach($expr);
            //$variableName = $expr->name;// instanceof PhpParser\Node\Identifier ? $expr->name->name : $expr->name;
            // 处理变量
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $variableName = $prettyPrinter->prettyPrintExpr($expr);
            return [
                "var"=>"" . $variableName,
                "tac"=>[]
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\BinaryOp) {
            $this->processedNodes->attach($expr);
            // 处理二元运算符
            $left = $this->handleExpr($expr->left);
            $right = $this->handleExpr($expr->right);
            //$tempVar = $this->createTempVar();
            //$tac = new ThreeAddressInstruction("=",$left . " " . $this->getOperator($expr) . " " . $right,$tempVar);
            //$this->threeAddressCode[] = $tac;
            //return $tempVar;
            $tacs = [];
            $tacs = array_merge($tacs,$left["tac"]);
            $tacs = array_merge($tacs,$right["tac"]);
            //$tacs[] = $tempVar . " = " .$left["var"] . " " . $this->getOperator($expr) . " " . $right["var"];
            return [
                "var"=>$left["var"] . " " . $this->getOperator($expr) . " " . $right["var"],
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Scalar\String_) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($expr);
            // 处理字符串addslashes($expr->value)
            return [
                "var"=>$propertyAccess,#'"' . $propertyAccess . '"',
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\UnaryOp) {
            $this->processedNodes->attach($expr);
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $expr->getOperatorSigil() . " " . $this->handleExpr($expr->expr) . "\n";
            // return $tempVar;
            $expDict = $this->handleExpr($expr->expr);
            $tacs = [];
            $tacs = array_merge($tacs,$expDict["tac"]);
            return [
                "var"=>$expr->getOperatorSigil() . " " . $expDict["var"],
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\FuncCall) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $name = $this->handleName($expr->name);
            //$tacs = array_merge($tacs,$nameDict["tac"]);
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVarDict = $this->handleExpr($arg->value);
                $argVars[] = $argVarDict["var"];
                $tacs = array_merge($tacs,$argVarDict["tac"]);
            }
            $tempVar = $this->createTempVar();
            // $tac = new ThreeAddressInstruction("",$expr->name . "(" . implode(", ", $argVars) . ")","",$tempVar);
            $tacs[] = $tempVar . " = " . $name . "(" . implode(", ", $argVars) . ");";
            // return $expr->name . "(" . implode(", ", $argVars) . ")";
            return [
                "var"=>$tempVar,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\MethodCall) {
            $this->processedNodes->attach($expr);
            $mNameDict = $this->handleName($expr->name);
            $tacs = [];
            //$tacs = array_merge($tacs,$mNameDict["tac"]);
            $objectVar = $this->handleExpr($expr->var);
            $tacs = array_merge($tacs,$objectVar["tac"]);
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVarDict = $this->handleExpr($arg->value);
                $argVars[] = $argVarDict["var"];
                $tacs = array_merge($tacs,$argVarDict["tac"]);
            }
            $tempVar = $this->createTempVar();
            //$tac = new ThreeAddressInstruction("",$objectVar["var"] . "." . $expr->name->name . "(" . implode(", ", $argVars) . ")","",$tempVar);
            
            $tacs[] = $tempVar . " = " . $objectVar["var"] . "." . $mNameDict . "(" . implode(", ", $argVars) . ");";
            return [
                "var"=>$tempVar,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\PropertyFetch) {
            $this->processedNodes->attach($expr);
            $propName = $this->handleName($expr->name);
            $expDict = $this->handleExpr($expr->var);
            $tacs = [];
            $tacs = array_merge($tacs,$expDict["tac"]);
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $this->handleExpr($expr->var) . "->" . $expr->name->name . "\n";
            // return $tempVar;
            return [
                "var"=>$expDict["var"] . "->" . $propName,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\New_) {
            $this->processedNodes->attach($expr);
            // 处理新对象的实例化
            // return 'new ' . $expr->class->toString();
            $tacs = [];
            $className = $this->handleName($expr->class);
            $args = [];
            foreach ($expr->args as $arg) {
                $argDict = $this->handleExpr($arg->value);
                $tacs = array_merge($tacs,$argDict["tac"]);
                $args[] = $argDict["var"];
            }
            return [
                "var"=>"new " . $className . "(" . implode(", ", $args) . ")",
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            // 处理数组元素获取
            $varDict = $this->handleExpr($expr->var);
            $dimDict = $this->handleExpr($expr->dim);
            $tacs = array_merge($tacs,$varDict["tac"]);
            $tacs = array_merge($tacs,$dimDict["tac"]);
            return [
                "var"=>$varDict["var"] . '[' . $dimDict["var"] . ']',
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\BooleanNot) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            // 处理布尔取反操作
            $exprDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($tacs,$exprDict["tac"]);
            return [
                "var"=>'!' . $exprDict["var"],
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Scalar\Encapsed) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            // 处理字符串插值
            // return implode("", array_map([$this, "handleExpr"], $expr->parts));
            // $parts = array_map(function ($part) {
            //     if ($part instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            //         return $part->value;
            //     } else {
            //         $exprDict = $this->handleExpr($part);
            //         $tacs = array_merge($tacs,$exprDict["tac"]);
            //         return $exprDict["var"];
            //     }
            // }, $expr->parts);
            foreach($expr->parts as $part){
                if ($part instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
                    $parts[] = $part->value;
                } else {
                    $exprDict = $this->handleExpr($part);
                    $tacs = array_merge($tacs,$exprDict["tac"]);
                    $parts[] = $exprDict["var"];
                }
            }
            return [
                "var"=>'"' . implode('', $parts) . '"',
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\ClassConstFetch){
            $this->processedNodes->attach($expr);
            $tacs = [];
            // Assume that $this->handleName can convert a Name node to a string.
            $classNameDict = $this->handleName($expr->class);
            //$tacs = array_merge($tacs,$classNameDict["tac"]);
            return [
                "var"=>"{$classNameDict}::{$expr->name}",
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\ConstFetch){
            $this->processedNodes->attach($expr);
            $tacs = [];
            return [
                "var"=>$expr->name->toString(), // 常量的名称
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\Empty_){
            $this->processedNodes->attach($expr);
            $tacs = [];
            $exprNameDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($tacs,$exprNameDict["tac"]);
            return [
                "var"=>"empty(".$exprNameDict["var"].")",
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\ErrorSuppress) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            // 在 handleExpr() 方法中添加对 PhpParser\Node\Expr\ErrorSuppress 类型的处理
            // 处理 ErrorSuppress 类型的表达式
            //$tempVar = $this->createTempVar();
            $exprDict = $this->handleExpr($expr->expr);
            //$tempVar = "@" . $exprDict["var"];
            $tacs = array_merge($tacs,$exprDict["tac"]);
            return [
                "var"=>"" . $exprDict["var"],
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\PostInc) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $varDict = $this->handleExpr($expr->var);
            $tacs = array_merge($tacs,$varDict["tac"]);
            return [
                "var"=>$varDict["var"] . '++',
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            // 处理字符串插值的一部分
            return [
                "var"=>$expr->value,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\Array_) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $elements = $expr->items;
            $arrayVars = [];
            foreach ($elements as $element) {
                $arrayVarDict = $this->handleExpr($element->value);
                $arrayVars[] = $arrayVarDict["var"];
                $tacs = array_merge($tacs,$arrayVarDict["tac"]);
            }
            //$tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = [" . implode(", ", $arrayVars) . "]";
            // return $tempVar;
            return [
                "var"=>"[".implode(", ",$arrayVars) . "]",
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\AssignOp) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $varDict = $this->handleExpr($expr->var);
            $exprDict = $this->handleExpr($expr->expr);
            //$tempVar = $this->createTempVar();
            //return $tempVar . " = " . $var . " . " . $expr;
            $tacs = array_merge($tacs,$varDict["tac"]);
            $tacs = array_merge($tacs,$exprDict["tac"]);
            return [
                "var"=>$varDict["var"] . $this->getAssignOp($expr) . $exprDict["var"],
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof Node\Scalar\LNumber) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            return [
                "var"=>$expr->value,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\Exit_) {
            $this->processedNodes->attach($expr);
        
            // 在处理 Exit_ 类型的表达式
            $exprNameDict = $expr->expr !== null ? $this->handleExpr($expr->expr) : ["var" => "", "tac" => []];
            $tacs = [];
            $tacs = array_merge($tacs, $exprNameDict["tac"]);
        
            return [
                "var" => "exit(" . $exprNameDict["var"] . ")",
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\Isset_) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $vars = [];
            foreach ($expr->vars as $var) {
                $varDict = $this->handleExpr($var);
                $vars[] = $varDict["var"];
                $tacs = array_merge($tacs, $varDict["tac"]);
            }
            return [
                "var" => "isset(" . implode(", ", $vars) . ")",
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\Ternary) {
            $this->processedNodes->attach($expr);
            $condDict = $this->handleExpr($expr->cond);
            $ifDict = $this->handleExpr($expr->if);
            $elseDict = $this->handleExpr($expr->else);
            $tacs = array_merge($condDict["tac"], $ifDict["tac"], $elseDict["tac"]);
            return [
                "var" => "(" . $condDict["var"] . " ? " . $ifDict["var"] . " : " . $elseDict["var"] . ")",
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\StaticCall) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            
            // 处理静态方法调用的类名
            $classNameDict = $this->handleName($expr->class);
            //$tacs = array_merge($tacs, $classNameDict["tac"]);
            
            // 处理静态方法的参数
            $argVars = [];
            foreach ($expr->args as $arg) {
                $argVarDict = $this->handleExpr($arg->value);
                $argVars[] = $argVarDict["var"];
                $tacs = array_merge($tacs, $argVarDict["tac"]);
            }
            
            $tempVar = $this->createTempVar();
            $tacs[] = $tempVar . " = " . $classNameDict . "::" . $expr->name . "(" . implode(", ", $argVars) . ");";
            
            return [
                "var" => $tempVar,
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\UnaryMinus) {
            $this->processedNodes->attach($expr);
            $expDict = $this->handleExpr($expr->expr);
            $tacs = $expDict["tac"];
            return [
                "var" => '-' . $expDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\UnaryPlus) {
            // Handle unary plus expression
            $this->processedNodes->attach($expr);
            $expDict = $this->handleExpr($expr->expr);
            $tacs = $expDict["tac"];
            return [
                "var" => '+' . $expDict["var"],
                "tac" => $tacs
            ];
        }         
        elseif ($expr instanceof PhpParser\Node\Expr\Include_) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $exprDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($tacs, $exprDict["tac"]);
            
            if ($expr->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE) {
                $tacs[] = 'include ' . $exprDict["var"] . ";";
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE) {
                $tacs[] = 'include_once ' . $exprDict["var"] . ";";
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE) {
                $tacs[] = 'require ' . $exprDict["var"] . ";";
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE) {
                $tacs[] = 'require_once ' . $exprDict["var"] . ";";
            }
            
            return [
                "var" => "",
                "tac" => $tacs
            ];
        } 
        // elseif ($expr instanceof PhpParser\Node\Expr\AssignOp\Plus) {
        //     $this->processedNodes->attach($expr);
        //     $varDict = $this->handleExpr($expr->var);
        //     $exprDict = $this->handleExpr($expr->expr);
        //     $tacs = array_merge($varDict["tac"], $exprDict["tac"]);
        //     return [
        //         "var" => $varDict["var"] . ' += ' . $exprDict["var"],
        //         "tac" => $tacs
        //     ];
        // } 
        elseif ($expr instanceof PhpParser\Node\Expr\List_) {
            $this->processedNodes->attach($expr);
            $vars = [];
            $tacs = [];
            foreach ($expr->items as $item) {
                if ($item === null) {
                    $vars[] = '';
                } else {
                    $varDict = $this->handleExpr($item->value);
                    $vars[] = $varDict["var"];
                    $tacs = array_merge($tacs, $varDict["tac"]);
                }
            }
            $listVar = implode(', ', $vars);
            return [
                "var" => "list(" . $listVar . ")",
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Scalar\DNumber) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            return [
                "var" => $expr->value,
                "tac" => $tacs
            ];
        } // Handle closure expressions
        elseif ($expr instanceof PhpParser\Node\Expr\Closure) {
            $this->processedNodes->attach($expr);
            $paramNames = [];
            foreach($expr->params as $param){
                $paramNodes = $this->handleNode($param);
                $paramNames = array_merge($paramNames,$paramNodes);
            }
            //array_map([$this, 'handleNode'], $expr->params);
            //$paramNames = array_column($paramNameDicts, 'var');
            $paramTacs = [];//array_merge(...array_column($paramNameDicts, 'tac'));
            $stmtsNames = $this->generateStatements($expr->stmts);
            //$stmtsNames = array_column($stmtsNameDicts, 'var');
            // $stmtsTacs = array_merge(...array_column($stmtsNameDicts, 'tac'));
            return [
                "var" => 'function (' . implode(', ', $paramNames) . ') { ' . implode('; ', $stmtsNames) . ' }',
                "tac" => []//array_merge($paramTacs, $stmtsTacs)
            ];
        }
        // Handle closure use expressions
        elseif ($expr instanceof PhpParser\Node\Expr\ClosureUse) {
            $this->processedNodes->attach($expr);
            $varNameDict = $this->handleExpr($expr->var);
            return [
                "var" => 'use (' . $varNameDict["var"] . ')',
                "tac" => $varNameDict["tac"]
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\YieldFrom) {
            $this->processedNodes->attach($expr);
            $exprNameDict = $this->handleExpr($expr->expr);
            return [
                "var" => 'yield from ' . $exprNameDict["var"],
                "tac" => $exprNameDict["tac"]
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            $this->processedNodes->attach($expr);
            $nameDict = $this->handleName($expr->name);
            $className = $this->handleName($expr->class);
            return [
                "var" => $className . '::$' . $nameDict,
                "tac" => []//$nameDict["tac"]
            ];
        }
        // Handle clone expressions
        elseif ($expr instanceof PhpParser\Node\Expr\Clone_) {
            $this->processedNodes->attach($expr);
            $exprNameDict = $this->handleExpr($expr->expr);
            return [
                "var" => 'clone ' . $exprNameDict["var"],
                "tac" => $exprNameDict["tac"]
            ];
        }
        // Handle eval expressions
        elseif ($expr instanceof PhpParser\Node\Expr\Eval_) {
            $this->processedNodes->attach($expr);
            $exprNameDict = $this->handleExpr($expr->expr);
            return [
                "var" => 'eval(' . $exprNameDict["var"] . ')',
                "tac" => $exprNameDict["tac"]
            ];
        }

        // Handle instanceof expressions
        elseif ($expr instanceof PhpParser\Node\Expr\Instanceof_) {
            $this->processedNodes->attach($expr);
            $exprNameDict = $this->handleExpr($expr->expr);
            $className = $this->handleName($expr->class);
            return [
                "var" => $exprNameDict["var"] . ' instanceof ' . $className,
                "tac" => $exprNameDict["tac"]
            ];
        }

        // Handle post-decrement expressions
        elseif ($expr instanceof PhpParser\Node\Expr\PostDec) {
            $this->processedNodes->attach($expr);
            $varNameDict = $this->handleExpr($expr->var);
            return [
                "var" => $varNameDict["var"] . '--',
                "tac" => $varNameDict["tac"]
            ];
        }

        // Handle pre-decrement expressions
        elseif ($expr instanceof PhpParser\Node\Expr\PreDec) {
            $this->processedNodes->attach($expr);
            $varNameDict = $this->handleExpr($expr->var);
            return [
                "var" => '--' . $varNameDict["var"],
                "tac" => $varNameDict["tac"]
            ];
        }

        // Handle pre-increment expressions
        elseif ($expr instanceof PhpParser\Node\Expr\PreInc) {
            $this->processedNodes->attach($expr);
            $varNameDict = $this->handleExpr($expr->var);
            return [
                "var" => '++' . $varNameDict["var"],
                "tac" => $varNameDict["tac"]
            ];
        }

        // Handle print expressions
        elseif ($expr instanceof PhpParser\Node\Expr\Print_) {
            $this->processedNodes->attach($expr);
            $exprNameDict = $this->handleExpr($expr->expr);
            return [
                "var" => 'print ' . $exprNameDict["var"],
                "tac" => $exprNameDict["tac"]
            ];
        }

        // Handleshell exec expressions```php
        elseif ($expr instanceof PhpParser\Node\Expr\ShellExec) {
            $this->processedNodes->attach($expr);
            $parts = array_map([$this, 'handleExpr'], $expr->parts);
            $partsVars = array_column($parts, 'var');
            $partsTacs = array_merge(...array_column($parts, 'tac'));
            return [
                "var" => '`' . implode(' ', $partsVars) . '`',
                "tac" => $partsTacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\Int_) {
            $this->processedNodes->attach($expr);
            // Handle integer cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            // Example: Generate code for integer casting
            return [
                "var" => '(int) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\AssignRef) {
            $this->processedNodes->attach($expr);
            // Handle reference assignment expression
            $tacs = [];
            $varDict = $this->handleExpr($expr->var);
            $tacs = array_merge($tacs,$varDict["tac"]);
            $exprDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($tacs,$exprDict["tac"]);
            // Example: Generate code for reference assignment
            return [
                "var" => $varDict["var"] . ' = &' . $exprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\File) {
            $this->processedNodes->attach($expr);
            // Handle '__FILE__' magic constant
            // Example: Generate code for retrieving the current file path and name
            return [
                "var" => '__FILE__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Function_) {
            $this->processedNodes->attach($expr);
            // Handle '__FUNCTION__' magic constant
            // Example: Generate code for retrieving the name of the current function
            return [
                "var" => '__FUNCTION__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Line) {
            $this->processedNodes->attach($expr);
            // Handle '__LINE__' magic constant
            // Example: Generate code for retrieving the current line number
            return [
                "var" => '__LINE__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Dir) {
            $this->processedNodes->attach($expr);
            // Handle '__DIR__' magic constant
            // Example: Generate code for retrieving the directory of the current file
            return [
                "var" => '__DIR__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Class_) {
            $this->processedNodes->attach($expr);
            // Handle '__CLASS__' magic constant
            // Example: Generate code for retrieving the name of the current class
            return [
                "var" => '__CLASS__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Method) {
            $this->processedNodes->attach($expr);
    
            // 在这里添加你处理 __METHOD__ 魔术常量的逻辑
            // 例如，你可能需要创建一个新的临时变量，然后将 __METHOD__ 魔术常量的值赋值给这个变量
            return [
                "var" => '__METHOD__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Scalar\MagicConst\Namespace_) {
            $this->processedNodes->attach($expr);
    
            // 在这里添加你处理 __NAMESPACE__ 魔术常量的逻辑
            // 例如，你可能需要创建一个新的临时变量，然后将 __NAMESPACE__ 魔术常量的值赋值给这个变量
            return [
                "var" => '__NAMESPACE__',
                "tac" => []
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\BitwiseNot) {
            $this->processedNodes->attach($expr);
            // Handle bitwise not expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            // Example: Generate code for bitwise not operation
            return [
                "var" => '~' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\String_) {
            $this->processedNodes->attach($expr);
            // Handle string cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            // Example: Generate code for string casting
            return [
                "var" => '(string) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\Double) {
            $this->processedNodes->attach($expr);
            // Handle double (float) cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            return [
                "var" => '(float) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\Array_) {
            $this->processedNodes->attach($expr);
            // Handle array cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            return [
                "var" => '(array) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\Bool_) {
            $this->processedNodes->attach($expr);
            // Handle boolean cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            return [
                "var" => '(bool) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        elseif ($expr instanceof PhpParser\Node\Expr\Cast\Object_) {
            $this->processedNodes->attach($expr);
            // Handle object cast expression
            $subExpr = $expr->expr;
            $subExprDict = $this->handleExpr($subExpr);
            $tacs = [];
            $tacs = array_merge($tacs,$subExprDict["tac"]);
            return [
                "var" => '(object) ' . $subExprDict["var"],
                "tac" => $tacs
            ];
        }
        
        else {
            throw new Exception("Unsupported expression type: " . get_class($expr));
        }
        
    }
    public function handleNode(PhpParser\Node $node){
        if ($this->processedNodes->contains($node)) {
            return [];
        }
        $code = [];
        if ($node instanceof PhpParser\Node\Stmt\If_) {
            $this->processedNodes->attach($node);
            // 处理 if 语句
            $conditionDict = $this->handleExpr($node->cond);
            $ifStmts = $this->generateStatements($node->stmts);
            $elseStmts = $node->else !== null ? $this->generateStatements($node->else->stmts) : [];    
            $ifLabel = $this->createLabel();
            $elseLabel = $node->else !== null ? $this->createLabel() : null;
            $endLabel = $this->createLabel();
            $code = array_merge($code,$conditionDict["tac"]);
            $code[] = "if (" . $conditionDict["var"] . ") goto " . $ifLabel['start'] . ";";
            $code[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']) . ";";    
            $code[] = $ifLabel['start'] . ":";
            $code = array_merge($code, $ifStmts);
            $code[] = "goto " . $endLabel['start'] . ";";    
            if ($elseLabel !== null) {
                $code[] = $elseLabel['start'] . ":";
                $code = array_merge($code, $elseStmts);
                $code[] = "goto " . $endLabel['start'] . ";";
            }    
            $code[] = $endLabel['start'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\ElseIf_) {
            $this->processedNodes->attach($node);
            $exprDict = $this->handleExpr($node->cond);
            $code = array_merge($code,$exprDict["tac"]);
            $code[] = "if (" . $exprDict["var"] . ") goto " . "Label for elseif branch;\n";
            // Recurse into "elseif" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\Else_) {
            $this->processedNodes->attach($node);
            // Handle the else statement
            // For example, you might generate code for the statements inside the else block
            foreach ($node->stmts as $innerStmt) {
                $code = array_merge($code, $this->generateStatements([$innerStmt]));
            }
            // Recurse into "else" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\While_) {
            $this->processedNodes->attach($node);
            // 处理 while 循环
            $conditionDict = $this->handleExpr($node->cond);
            $stmts = $this->generateStatements($node->stmts);    
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();
            $code = array_merge($code,$conditionDict["tac"]);
            $code[] = $startLabel['start'] . ":";
            $code[] = "if (" . $conditionDict["var"] . ") goto " . $loopLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";    
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "if (" . $conditionDict["var"] . ") goto " . $startLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\For_) {
            $this->processedNodes->attach($node);
            // 处理 for 循环
            $initStmts = $node->init !== null ? $this->generateStatements($node->init) : [];
            $conditionDict = null;
            if ($node->cond !== null) {
                if (is_array($node->cond)) {
                    $conditionDict = [];
                    foreach ($node->cond as $cond) {
                        $conditionDict[] = $this->handleExpr($cond);
                    }
                } else {
                    $conditionDict = $this->handleExpr($node->cond);
                }
            }
            $loopStmts = $node->loop !== null ? $this->generateStatements($node->loop) : [];
            $stmts = $this->generateStatements($node->stmts);
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();
            if ($conditionDict !== null && !is_array($conditionDict)) {
                $code = array_merge($code, $conditionDict["tac"]);
            }
            $initStmts[] = ";\n";
            $code = array_merge($code, $initStmts);
            
            $code[] = $startLabel['start'] . ":";
            if ($conditionDict !== null) {
                if (is_array($conditionDict)) {
                    $conditionLabels = [];
                    foreach ($conditionDict as $index => $condition) {
                        $conditionLabels[] = $this->createLabel();
                        if ($index > 0) {
                            $code[] = $conditionLabels[$index - 1]['end'] . ":";
                        }
                        $code = array_merge($code, $condition["tac"]);
                        $code[] = ";\n";
                        $code[] = "if (" . $condition["var"] . ") goto " . $conditionLabels[$index]['start'] . ";";
                        $code[] = "goto " . $loopLabel['end'] . ";";
                    }
                    $code[] = $conditionLabels[count($conditionDict) - 1]['end'] . ":";
                } else {
                    $code[] = "if (" . $conditionDict["var"] . ") goto " . $loopLabel['start'] . ";";
                    $code[] = "goto " . $loopLabel['end'] . ";";
                }
            } else {
                $code[] = "goto " . $loopLabel['start'] . ";";
            }
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $loopStmts[] = ";\n";
            $code = array_merge($code, $loopStmts);
            if ($conditionDict !== null && !is_array($conditionDict)) {
                $code[] = "if (" . $conditionDict["var"] . ") goto " . $startLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
            } else {
                $code[] = "goto " . $startLabel['start'] . ";";
            }
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Foreach_) {
            $this->processedNodes->attach($node);
            // 处理 Foreach 循环
            $valueVarDict = $this->handleExpr($node->valueVar);
            $arrayVarDict = $this->handleExpr($node->expr);
            $code = array_merge($code,$valueVarDict["tac"]);
            $code = array_merge($code,$arrayVarDict["tac"]);
            $stmts = $this->generateStatements($node->stmts);
            $loopLabel = $this->createLabel();
            $code[] = "foreach (" . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . ") goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "foreach (" . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . ") goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Class_) {
            $this->processedNodes->attach($node);
            // 处理类定义
            $className = $this->handleName($node->name);

            // 处理类的抽象修饰符
            $abstract = "";
            if ($node->isAbstract()) {
                $abstract = "abstract ";
            }

            // 处理类的继承
            $extends = "";
            if ($node->extends) {
                $extends = " extends " . $node->extends->toString();
            }

            // 处理类的接口实现
            $implements = "";
            if (!empty($node->implements)) {
                $implements = " implements " . implode(", ", array_map(function($i) { return $i->toString(); }, $node->implements));
            }

            $code[] = $abstract . " class " . $className . $extends . $implements . "{";

            $code = array_merge($code, $this->generateStatements($node->stmts));
            $code[] = "}";
            // $this->threeAddressCode = array_merge($this->threeAddressCode,$code);
        } elseif ($node instanceof PhpParser\Node\Stmt\Function_) {
            $this->processedNodes->attach($node);
            // 处理函数定义
            $functionName = $this->handleName($node->name);
            $code[] = "function " . $functionName;

            $params = [];
            foreach ($node->params as $param) {
                $paramName = $this->handleNode($param);
                $params = array_merge($params,$paramName);
            }
            $code[] = "( " . implode(", ",$params) . " ){"; 


            $code = array_merge($code, $this->generateStatements($node->stmts));

            $code[] = "}";
            //$this->threeAddressCode = array_merge($this->threeAddressCode,$code);//['label' => $functionName, 'code' => $code];
        } elseif ($node instanceof PhpParser\Node\Expr\Assign) {
            $this->processedNodes->attach($node);
            // 处理赋值语句
            $varDict = $this->handleExpr($node->var);
            $valueDict = $this->handleExpr($node->expr);
            $code = array_merge($code,$varDict["tac"]);
            $code = array_merge($code,$valueDict["tac"]);
            $code[] = $varDict["var"] . " = " . $valueDict["var"] . ";";
        } elseif ($node instanceof PhpParser\Node\Expr\MethodCall) {
            $this->processedNodes->attach($node);
            $mName = $this->handleName($node->name);
            // 先处理对象表达式
            $objectVarDict = $this->handleExpr($node->var);     
            $code = array_merge($code,$objectVarDict["tac"]);       
            // 然后处理参数列表
            $argVars = [];
            foreach ($node->args as $arg) {
                $argVarDict = $this->handleExpr($arg->value);
                $code = array_merge($code,$argVarDict["tac"]);
                $argVars[] = $argVarDict["var"];
            }
            // 进行方法调用
            $tempVar = $this->createTempVar();
            $code[] = $tempVar . " = " . $objectVarDict["var"] . "." . $mName . "(" . implode(", ", $argVars) . ");";
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall) {
            $this->processedNodes->attach($node);
            $name = $this->handleName($node->name);
            $args = [];
            foreach ($node->args as $arg) {
                $argsDict = $this->handleExpr($arg->value);
                $code = array_merge($code,$argsDict["tac"]);
                $args[] = $argsDict["var"];
            }
            //$tempVar = $this->createTempVar();
            $code[] = $name . "(" . implode(", ", $args) . ")" . ";";
        } elseif ($node instanceof PhpParser\Node\Expr\PostInc) {
            $this->processedNodes->attach($node);
            // 处理后自增表达式
            // 可以获取变量信息
            
            $varNameDict = $this->handleExpr($node->var);
            
            // 在这里可以根据需要生成相应的三地址码
            
            // 示例：将后自增语句添加到三地址码数组
            $code = array_merge($code,$varNameDict["tac"]);
            $code[] = $varNameDict["var"] . ' = ' . $varNameDict["var"] . ' + 1';
        } elseif ($node instanceof PhpParser\Node\Param) {

                $this->processedNodes->attach($node);
                // 处理函数或方法的参数
                // 可以根据需要获取参数的名称、默认值等信息
                $paramName = $node->var->name;
                $defaultValue = $node->default ? $this->handleExpr($node->default)["var"] : null;
                
                // 在这里可以根据需要生成相应的三地址码
                
                // 示例：将参数名称和默认值添加到三地址码数组
                if($defaultValue==null){
                    $code[] = "$" . $paramName;
                } else {
                    $code[] = "$" . $paramName . ' = ' . $defaultValue;
                }
        } elseif ($node instanceof PhpParser\Node\Expr\Include_) {
            $this->processedNodes->attach($node);
            $exprDict = $this->handleExpr($node->expr);
            $code = array_merge($code, $exprDict["tac"]);
            
            if ($node->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE) {
                $code[] = 'include( ' . $exprDict["var"] . " );";
            } elseif ($node->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE) {
                $code[] = 'include_once( ' . $exprDict["var"] . " );";
            } elseif ($node->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE) {
                $code[] = 'require( ' . $exprDict["var"] . " );";
            } elseif ($node->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE) {
                $code[] = 'require_once( ' . $exprDict["var"] . " );";
            }
        } 
        elseif ($node instanceof PhpParser\Node\Stmt\Echo_) {
            $this->processedNodes->attach($node);
            // Handle 'echo' statement
            $expressions = $node->exprs;
            foreach ($expressions as $expression) {
                // Example: Generate code for each expression
                $exprDict = $this->handleExpr($expression);
                $code = array_merge($code,$exprDict["tac"]);
                $code[] = 'echo ' . $exprDict["var"] . ';';
            }
        }
        // elseif ($node instanceof PhpParser\Node\Stmt) {
        //     $nodeTacs = $this->generateStatements([$node]);
        //     $code = array_merge($code,$nodeTacs);
        // }
        // else {
        //     throw new Exception("Unsupported node type: " . get_class($node));
        // }
        // elseif ($node instanceof PhpParser\Node\Stmt\Expression) {
        //     $this->processedNodes->attach($node);
        //     // 处理表达式语句
        //     $exprDict = $this->handleExpr($node->expr);
        //     $code = array_merge($code,$exprDict["tac"]);
        //     $code[] = $exprDict["var"];
        // }
        if ($node instanceof PhpParser\Node\Stmt\Expression){
            $code[] = ";\n";
        }
        
        return $code; 
    }
    public function enterNode(PhpParser\Node $node) {
        // $this->threeAddressCode[] = $node;
        if ($this->processedNodes->contains($node)) {
             return; // 跳过已处理节点
        }else{
            $code = $this->handleNode($node);
            // if($node instanceof PhpParser\Node\Stmt\Expression){
            //     $code[] = ";\n";
            // }
            $this->threeAddressCode = array_merge($this->threeAddressCode,$code);
        }
    }
    public function getThreeAddressCode() {
        return $this->threeAddressCode;
    }
    public function handleName($name)
    {
        if ($this->processedNodes->contains($name)) {
            return "";
        }
        $this->processedNodes->attach($name);
        if ($name instanceof PhpParser\Node\Identifier) {
            return $name->name;
        } elseif ($name instanceof PhpParser\Node\Name) {
            return $name->getLast();
        } elseif ($name instanceof PhpParser\Node\Expr\Variable){
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $variableName = $prettyPrinter->prettyPrintExpr($name);
            return $variableName;
        } elseif ($name instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $arrayAccess = $prettyPrinter->prettyPrintExpr($name);
            return $arrayAccess;
        } elseif ($name instanceof PhpParser\Node\Expr\PropertyFetch) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } elseif ($name instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } elseif ($name instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } elseif ($name instanceof PhpParser\Node\Expr\MethodCall) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } elseif ($name instanceof PhpParser\Node\Scalar\String_) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } elseif ($name instanceof PhpParser\Node\Scalar\Encapsed) {
            $prettyPrinter = new PhpParser\PrettyPrinter\Standard();
            $propertyAccess = $prettyPrinter->prettyPrintExpr($name);
            return $propertyAccess;
        } 
        // return implode('\\', $name->parts);
        else {
            throw new Exception("Unsupported handleName type: " . get_class($name));
        }
    }
    
    public function getAssignOp($operator) {
        switch (get_class($operator)) {
            case PhpParser\Node\Expr\AssignOp\Plus::class:
                return '+=';
            case PhpParser\Node\Expr\AssignOp\Minus::class:
                return '-=';
            case PhpParser\Node\Expr\AssignOp\Mul::class:
                return '*=';
            case PhpParser\Node\Expr\AssignOp\Div::class:
                return '/=';
            case PhpParser\Node\Expr\AssignOp\Mod::class:
                return '%=';
            // 这里添加更多你需要处理的运算符类型
            case PhpParser\Node\Expr\AssignOp\Concat::class:
                return '.=';
            case PhpParser\Node\Expr\AssignOp\ShiftLeft::class:
                return '<<=';
            case PhpParser\Node\Expr\AssignOp\ShiftRight::class:
                return '>>=';
            case PhpParser\Node\Expr\AssignOp\Coalesce::class:
                return '??=';
            case PhpParser\Node\Expr\AssignOp\BitwiseAnd::class:
                return '&=';
            case PhpParser\Node\Expr\AssignOp\BitwiseOr::class:
                return '|=';
            case PhpParser\Node\Expr\AssignOp\BitwiseXor::class:
                return '^=';
            case PhpParser\Node\Expr\AssignOp\Pow::class:
                return '**=';
            default:
                throw new Exception("Unsupported AssignOp operator type: " . get_class($operator));
        }
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
            case PhpParser\Node\Expr\BinaryOp\Mod::class:
                return '%';
            // 这里添加更多你需要处理的运算符类型
            case PhpParser\Node\Expr\BinaryOp\Concat::class:
                return '.';
            case PhpParser\Node\Expr\BinaryOp\ShiftLeft::class:
                return '<<';
            case PhpParser\Node\Expr\BinaryOp\ShiftRight::class:
                return '>>';
            case PhpParser\Node\Expr\BinaryOp\Smaller::class:
                return '<';
            case PhpParser\Node\Expr\BinaryOp\SmallerOrEqual::class:
                return '<=';
            case PhpParser\Node\Expr\BinaryOp\Greater::class:
                return '>';
            case PhpParser\Node\Expr\BinaryOp\GreaterOrEqual::class:
                return '>=';
            case PhpParser\Node\Expr\BinaryOp\Equal::class:
                return '==';
            case PhpParser\Node\Expr\BinaryOp\NotEqual::class:
                return '!=';
            case PhpParser\Node\Expr\BinaryOp\Identical::class:
                return '===';
            case PhpParser\Node\Expr\BinaryOp\NotIdentical::class:
                return '!==';
            case PhpParser\Node\Expr\BinaryOp\Spaceship::class:
                return '<=>';
            case PhpParser\Node\Expr\BinaryOp\Coalesce::class:
                return '??';
            case PhpParser\Node\Expr\BinaryOp\BooleanAnd::class:
                return '&&';
            case PhpParser\Node\Expr\BinaryOp\BooleanOr::class:
                return '||';
            case PhpParser\Node\Expr\BinaryOp\LogicalAnd::class:
                return 'and';
            case PhpParser\Node\Expr\BinaryOp\LogicalOr::class:
                return 'or';
            case PhpParser\Node\Expr\BinaryOp\LogicalXor::class:
                return 'xor';
            case PhpParser\Node\Expr\BinaryOp\BitwiseAnd::class:
                return '&';
            case PhpParser\Node\Expr\BinaryOp\BitwiseOr::class:
                return '|';
            case PhpParser\Node\Expr\BinaryOp\BitwiseXor::class:
                return '^';
            case PhpParser\Node\Expr\BinaryOp\Pow::class:
                return '**';
            default:
                throw new Exception("Unsupported operator type: " . get_class($operator));
        }
    }
    private function generateStatements(array $stmts) {
        $code = [];
        $result = [];
        foreach ($stmts as $stmt) {
            if ($this->processedNodes->contains($stmt)) {
                continue;
            }
            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $this->processedNodes->attach($stmt);
                // 处理 if 语句
                $conditionDict = $this->handleExpr($stmt->cond);
                $ifStmts = $this->generateStatements($stmt->stmts);
                $elseStmts = $stmt->else !== null ? $this->generateStatements($stmt->else->stmts) : [];
                $ifLabel = $this->createLabel();
                $elseLabel = $stmt->else !== null ? $this->createLabel() : null;
                $endLabel = $this->createLabel();
                $code = array_merge($code,$conditionDict["tac"]);
                $code[] = "if (" . $conditionDict["var"] . ") goto " . $ifLabel['start'] . ";";
                $code[] = "goto " . ($elseLabel !== null ? $elseLabel['start'] : $endLabel['start']) . ";";
                $code[] = $ifLabel['start'] . ":";
                $code = array_merge($code, $ifStmts);
                $code[] = "goto " . $endLabel['start'] . ";";
                if ($elseLabel !== null) {
                    $code[] = $elseLabel['start'] . ":";
                    $code = array_merge($code, $elseStmts);
                    $code[] = "goto " . $endLabel['start'] . ";";
                }
                $code[] = $endLabel['start'] . ":";
                // $result[] = ['label' => $ifLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ElseIf_) {
                $this->processedNodes->attach($stmt);
                $exprDict = $this->handleExpr($stmt->cond);
                $code = array_merge($code,$exprDict["tac"]);
                $code[] = "elseif (" . $exprDict["var"] . ") {";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}";
                // Recurse into "elseif" branch
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Else_) {
                $this->processedNodes->attach($stmt);
                // Handle the else statement
                // For example, you might generate code for the statements inside the else block
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                // Recurse into "else" branch
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $this->processedNodes->attach($stmt);
                // 处理 while 循环
                $conditionDict = $this->handleExpr($stmt->cond);
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code = array_merge($code,$conditionDict["tac"]);
                $code[] = $startLabel['start'] . ":";
                $code[] = "if (" . $conditionDict["var"] . ") goto " . $loopLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "if (" . $conditionDict["var"] . ") goto " . $startLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $this->processedNodes->attach($stmt);
                // 处理 for 循环
                $initStmts = $stmt->init !== null ? $this->generateStatements($stmt->init) : [];
                $conditionDict = null;
                if ($stmt->cond !== null) {
                    if (is_array($stmt->cond)) {
                        $conditionDict = [];
                        foreach ($stmt->cond as $cond) {
                            $conditionDict[] = $this->handleExpr($cond);
                        }
                    } else {
                        $conditionDict = $this->handleExpr($stmt->cond);
                    }
                }
                $loopStmts = $stmt->loop !== null ? $this->generateStatements($stmt->loop) : [];
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                if ($conditionDict !== null && $conditionDict !== [] && array_keys($conditionDict)!==range(0, count($conditionDict)-1)) {
                    $code = array_merge($code, $conditionDict["tac"]);
                }
                $initStmts[] = ";\n";
                $code = array_merge($code, $initStmts);
                $code[] = $startLabel['start'] . ":";
                if ($conditionDict !== null && $conditionDict !== []) {
                    if (array_keys($conditionDict)===range(0, count($conditionDict)-1)) {
                        $conditionLabels = [];
                        foreach ($conditionDict as $index => $condition) {
                            $conditionLabels[] = $this->createLabel();
                            if ($index > 0) {
                                $code[] = $conditionLabels[$index - 1]['end'] . ":";
                            }
                            $code = array_merge($code, $condition["tac"]);
                            $code[] = "if (" . $condition["var"] . ") goto " . $conditionLabels[$index]['start'] . ";";
                            $code[] = "goto " . $loopLabel['end'] . ";";
                        }
                        $code[] = $conditionLabels[count($conditionDict) - 1]['end'] . ":";
                    } else {
                        $code[] = "if (" . $conditionDict["var"] . ") goto " . $loopLabel['start'] . ";";
                        $code[] = "goto " . $loopLabel['end'] . ";";
                    }
                } else {
                    $code[] = "goto " . $loopLabel['start'] . ";";
                }
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $loopStmts[] = ";\n";
                $code = array_merge($code, $loopStmts);
                if ($conditionDict !== null && $conditionDict !== [] && array_keys($conditionDict)!==range(0, count($conditionDict)-1)) {
                    $code[] = "if (" . $conditionDict["var"] . ") goto " . $startLabel['start'] . ";";
                    $code[] = "goto " . $loopLabel['end'] . ";";
                } else {
                    $code[] = "goto " . $startLabel['start'] . ";";
                }
                $code[] = $loopLabel['end'] . ":";
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                $this->processedNodes->attach($stmt);
                // 处理 foreach 循环
                $valueVarDict = $this->handleExpr($stmt->valueVar);
                $arrayVarDict = $this->handleExpr($stmt->expr);
                $stmts = $this->generateStatements($stmt->stmts);
                $loopLabel = $this->createLabel();
                $code = array_merge($code,$valueVarDict["tac"]);
                $code = array_merge($code,$arrayVarDict["tac"]);
                $code[] = "foreach (" . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . ") goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                //$code[] = "foreach " . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . " goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $loopLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
                $this->processedNodes->attach($stmt);
                $funcName = $this->handleName($stmt->name);
                $argVars = [];
                foreach ($stmt->args as $arg) {
                    $argVarDict = $this->handleExpr($arg->value);
                    $code = array_merge($code,$argVarDict["tac"]);
                    $argVars[] = $argVarDict["var"];
                }
                $tempVar = $this->createTempVar();
                $code[] = $tempVar . " = call " . $funcName . "(" . implode(", ", $argVars) . ")";
                // $result[] = ['label' => $tempVar, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                $this->processedNodes->attach($stmt);
                // 处理类定义
                $className = $stmt->name;

                // 处理类的抽象修饰符
                $abstract = "";
                if ($stmt->isAbstract()) {
                    $abstract = "abstract ";
                }

                // 处理类的继承
                $extends = "";
                if ($stmt->extends) {
                    $extends = " extends " . $stmt->extends->toString();
                }

                // 处理类的接口实现
                $implements = "";
                if (!empty($stmt->implements)) {
                    $implements = " implements " . implode(", ", array_map(function($i) { return $i->toString(); }, $stmt->implements));
                }

                $code[] = $abstract . " class " . $className . $extends . $implements . "{";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}";
                // $result[] = ['label' => $className, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $this->processedNodes->attach($stmt);
                // 处理函数定义
                $functionName = $stmt->name;
                if( $stmt->getDocComment()!== null ){
                    $code[] = $stmt->getDocComment();
                }
                
                $code[] = "function " . $functionName;
                $params = [];
                foreach ($stmt->params as $param) {
                    $paramName = $this->handleNode($param);
                    $params = array_merge($params,$paramName);
                }
                $code[] = "( " . implode(", ",$params) . " ){"; 
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}";
                // $result[] = ['label' => $functionName, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $this->processedNodes->attach($stmt);
                // 处理异常处理
                $code[] = "try";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                foreach ($stmt->catches as $catch) {
                    $code = array_merge($code, $this->generateStatements([$catch]));
                }
                if ($stmt->finally !== null) {
                    $code = array_merge($code, $this->generateStatements($stmt->finally->stmts));
                }
                $code[] = "end_try";
                // $result[] = ['label' => 'try_catch', 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Catch_) {
                $this->processedNodes->attach($stmt);
                // 处理 catch
                $catchTypes = [];
                foreach($stmt->types as $type){
                    $catchTypeDict = $this->handleName($type);
                    $catchTypes[] = $catchTypeDict;
                    //$code = array_merge($code,$catchTypeDict["tac"]);
                }
                $code[] = 'catch (' . implode(" ",$catchTypes) . ' $' . $stmt->var->name . ') {';
                //$code[] = "catch " . $stmt->varType . " as " . $stmt->var->name;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "}";
                // $result[] = ['label' => $stmt->var->name, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Finally_) {
                $this->processedNodes->attach($stmt);
                // 处理 finally
                $code[] = "finally";
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_finally";
                // $result[] = ['label' => 'finally', 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
                $this->processedNodes->attach($stmt);
                // 处理赋值语句
                $varDict = $this->handleExpr($stmt->var);
                $exprDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code,$varDict["tac"]);
                $code = array_merge($code,$exprDict["tac"]);
                $code[] = $varDict["var"] . " = " . $exprDict["var"];
                // $result[] = ['label' => $var, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                $this->processedNodes->attach($stmt);
                if( $stmt->getDocComment()!== null ){
                    $code[] = $stmt->getDocComment();
                }
                // 处理属性定义
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->name;
                    $defaultValueDict = $prop->default !== null ? $this->handleExpr($prop->default) : null;
                    if ($defaultValueDict !== null){
                        $code = array_merge($code,$defaultValueDict["tac"]);
                        $code[] = "var $" . $propertyName . " = " . $defaultValueDict["var"] . ";";
                    } else{
                        $code[] = "var $" . $propertyName . ";";
                    }
                }
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                $this->processedNodes->attach($stmt);
                if( $stmt->getDocComment()!== null ){
                    $code[] = $stmt->getDocComment();
                }
                // 处理类方法定义
                $methodName = $stmt->name->name;
                $code[] = "function " . $methodName;
                
                $params = [];
                foreach ($stmt->params as $param) {
                    $paramName = $this->handleNode($param);
                    $params = array_merge($params,$paramName);
                }
                $code[] = "( " . implode(", ",$params) . " )"; 
                if ($stmt->stmts!==null){
                    $code[] = "{";
                    $code = array_merge($code, $this->generateStatements($stmt->stmts));
                    $code[] = "}";
                } else {
                    $code[] = ";";
                }
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $this->processedNodes->attach($stmt);
                $returnValueDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code,$returnValueDict["tac"]);
                $code[] = 'return ' . $returnValueDict["var"] . ";\n";
            } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc) {
                $this->processedNodes->attach($stmt);
                // 处理后自增表达式
                // 可以获取变量信息
                
                $varNameDict = $this->handleExpr($stmt->var);
                
                // 在这里可以根据需要生成相应的三地址码
                
                // 示例：将后自增语句添加到三地址码数组
                $code = array_merge($code,$varNameDict["tac"]);
                $code[] = $varNameDict["var"] . ' = ' . $varNameDict["var"] . ' + 1';
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                $this->processedNodes->attach($stmt);
                // Handle 'continue' statement
            
                // 在这里可以根据需要生成相应的三地址码
            
                // 示例：将'continue'语句添加到三地址码数组
                $code[] = 'continue;';
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $this->processedNodes->attach($stmt);
                // Handle 'switch' statement
                $cond = $stmt->cond;
                $condDict = $this->handleExpr($cond);
                $code = array_merge($code,$condDict["tac"]);
                $code[] = 'switch (' . $condDict["var"] . ') {';
                foreach ($stmt->cases as $case) {
                    $caseCond = $case->cond;
                    $caseCondDict = $this->handleExpr($caseCond);
                    $code = array_merge($code,$caseCondDict["tac"]);
                    if($caseCondDict["var"]!=""){
                        $code[] = 'case ' . $caseCondDict["var"] . ':';
                    }else{
                        $code[] = 'default :';
                    }
                    $caseStmts = $this->generateStatements($case->stmts);
                    $code = array_merge($code, $caseStmts);
                    $code[] = 'break;';
                }
                $code[] = '}';
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                $this->processedNodes->attach($stmt);
                // Handle 'break' statement
                $code[] = 'break;';
            }                   
            elseif ($stmt instanceof PhpParser\Node\Stmt\Expression) {
                $this->processedNodes->attach($stmt);
                // 处理表达式语句
                $exprDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code,$exprDict["tac"]);
                // $tempVar = $this->createTempVar();
                $code[] = $exprDict["var"];
            } 
            elseif ($stmt instanceof PhpParser\Node\Expr) {
                $this->processedNodes->attach($stmt);
                // 处理表达式语句
                $exprDict = $this->handleExpr($stmt);
                $code = array_merge($code,$exprDict["tac"]);
                // $tempVar = $this->createTempVar();
                $code[] = $exprDict["var"];
            } 
            elseif ($stmt instanceof PhpParser\Node\Stmt\Global_) {
                $this->processedNodes->attach($stmt);
                // Handle 'global' statement
                foreach ($stmt->vars as $var) {
                    $varNameDict = $this->handleExpr($var);
                    $code = array_merge($code,$varNameDict["tac"]);
                    $code[] = 'global ' . $varNameDict["var"] . ';';
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\Unset_) {
                $this->processedNodes->attach($stmt);
                // Handle 'unset' statement
                foreach ($stmt->vars as $var) {
                    $varDict = $this->handleExpr($var);
                    $code = array_merge($code,$varDict["tac"]);
                    $code[] = 'unset(' . $varDict["var"] . ');';
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\Echo_) {
                $this->processedNodes->attach($stmt);
                // Handle 'echo' statement
                $expressions = $stmt->exprs;
                foreach ($expressions as $expression) {
                    // Example: Generate code for each expression
                    $exprDict = $this->handleExpr($expression);
                    $code = array_merge($code,$exprDict["tac"]);
                    $code[] = 'echo ' . $exprDict["var"] . ';';
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                // Handle 'nop' statement (no-operation)
                // Example: Ignore the no-operation statement
                // Do nothing or perform any required handling
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\InlineHTML) {
                // Handle inline HTML statement
                // Example: Ignore or skip the inline HTML statement
                continue; // or do nothing
            }            
            elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $this->processedNodes->attach($stmt);
                // Handle do-while loop statement
                // Example: Generate code for do-while loop
                $condDict = $this->handleExpr($stmt->cond);
                $code = array_merge($code,$condDict["tac"]);
                $code[] = 'do {';
                $code = array_merge($code,$this->generateStatements($stmt->stmts));
                $code[] = '} while (' . $condDict["var"] . ');';
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {
                $this->processedNodes->attach($stmt);
                // Handle class constant declaration
                // Example: Generate code for class constant declaration
                foreach ($stmt->consts as $const) {
                    $constName = $const->name;
                    $constValueDict = $this->handleExpr($const->value);
                    $code = array_merge($code,$constValueDict["tac"]);
                    $code[] = 'const ' . $constName . ' = ' . $constValueDict['var'] . ';';
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\Static_) {
                $this->processedNodes->attach($stmt);
                // Handle static variable declaration
                // Example: Generate code for static variable declaration
                foreach ($stmt->vars as $var) {
                    $varNameDict = $var->var->name;
                    //$code = array_merge($code,$varNameDict["tac"]);
                    $varValueDict = $this->handleExpr($var->default);
                    $code = array_merge($code,$varValueDict["tac"]);
                    $code[] = 'static $' . $varNameDict . ' = ' . $varValueDict["var"] . ';';
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                $this->processedNodes->attach($stmt);
                // Handle throw statement
                // Example: Generate code for throw statement
                $exceptionDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code,$exceptionDict["tac"]);
                $code[] = 'throw ' . $exceptionDict["var"] . ';';
            }
            elseif ($stmt instanceof PhpParser\Node\Stmt\TraitUse) {
                $this->processedNodes->attach($stmt);
    
                // 在这里添加你处理 trait 引用的逻辑
                // 例如，你可能需要遍历 trait 引用的 traits，并为它们生成相应的代码
    
                $traits = [];
                foreach ($stmt->traits as $trait) {
                    // 处理 trait 引用的 trait
                    // 这里我们只是简单地获取 trait 的名称，你可能需要根据你的需求来修改这个处理逻辑
                    $traits[] = $trait->toString();
                }
                // 将 trait 引用添加到代码中
                // 这里我们只是简单地将 trait 引用转换为一个字符串，你可能需要根据你的需求来修改这个处理逻辑
                $code[] = 'use ' . implode(', ', $traits) . ';';
            }
            elseif ($stmt instanceof PhpParser\Node\Expr\Include_) {
                $this->processedNodes->attach($stmt);
                $exprDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code, $exprDict["tac"]);
                
                if ($stmt->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE) {
                    $code[] = 'include( ' . $exprDict["var"] . " );";
                } elseif ($stmt->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE) {
                    $code[] = 'include_once( ' . $exprDict["var"] . " );";
                } elseif ($stmt->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE) {
                    $code[] = 'require( ' . $exprDict["var"] . " );";
                } elseif ($stmt->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE) {
                    $code[] = 'require_once( ' . $exprDict["var"] . " );";
                }
            }
            elseif ($stmt instanceof PhpParser\Node\Expr\MethodCall) {
                $this->processedNodes->attach($stmt);
                $mName = $this->handleName($stmt->name);
                // 先处理对象表达式
                $objectVarDict = $this->handleExpr($stmt->var);     
                $code = array_merge($code,$objectVarDict["tac"]);       
                // 然后处理参数列表
                $argVars = [];
                foreach ($stmt->args as $arg) {
                    $argVarDict = $this->handleExpr($arg->value);
                    $code = array_merge($code,$argVarDict["tac"]);
                    $argVars[] = $argVarDict["var"];
                }
                // 进行方法调用
                $tempVar = $this->createTempVar();
                $code[] = $tempVar . " = " . $objectVarDict["var"] . "." . $mName . "(" . implode(", ", $argVars) . ");";
            }                          
            else {
                throw new Exception("Unsupported stmt type: " . get_class($stmt));
            }
            if ($stmt instanceof PhpParser\Node\Stmt\Expression){
                $code[] = ";\n";
            }
            $result = array_merge($result, $code);
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
    }
}
$file_path = '/home/leo/phpAVT-new/code_examples.php';
// 1. 创建解析器
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

function readCodeFile($file_path){
    // 2. 从文件读取PHP源代码
    $code = file_get_contents($file_path);
    return $code;
}

function phptotac($code){
    global $parser;
    try {       
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
        $final_code = implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
        $final_code = str_replace("\n;",";",$final_code);
        $final_code = preg_replace('/(.+)(\n;)/','${1};',$final_code);
        $final_code = preg_replace('/^_t\d{1,10};\n/m','',$final_code);
        $final_code = preg_replace('/;+$/m',';',$final_code);
        $final_code = preg_replace('/^;\n$/m','',$final_code);
        $final_code = preg_replace('/^};\n$/m','}',$final_code);
        //$final_code = preg_replace('/(^_L\d{1,10}:);\n/m','${1}\n',$final_code);
        /* echo "<?php\n" . $final_code . "\n?>";
        echo implode("\n",$modifiedStmts);*/
        $final_code = "<?php\n" . $final_code . "\n?>";
        return $final_code;
    } catch (Error $e) {
        return 'Parse Error: '. $e->getMessage();
    }
}

function convertPhpToTac($sourceDirectory, $destinationDirectory)
{
    // 确保目录结尾带有斜杠
    $sourceDirectory = rtrim($sourceDirectory, '/') . '/';
    $destinationDirectory = rtrim($destinationDirectory, '/') . '/';

    // 创建递归目录迭代器
    $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory);
    $iterator = new RecursiveIteratorIterator($directoryIterator);

    // 遍历源目录中的所有PHP文件
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        echo $file->getPathname() . "\n";
        $content = readCodeFile($file->getPathname());
        // 调用你的转换函数
        $convertedCode = phptotac($content);

        // 获取相对于源目录的相对路径
        $relativePath = substr($file->getPathname(), strlen($sourceDirectory));

        // 构建目标文件的路径
        $destinationFile = $destinationDirectory . $relativePath;

        // 确保目标目录存在
        $destinationDir = dirname($destinationFile);
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0777, true);
        }

        // 将转换后的代码写入目标文件
        file_put_contents($destinationFile, $convertedCode);
    }
}
function parse_dir(){
    global $argv;
    $scriptname = array_shift( $argv);
    $dir = (string) array_pop( $argv);
    // 调用函数
    convertPhpToTac($dir, $dir . '_TAC');
}

// $code = <<<'CODE'
// <?php $rss = 'test'; 
// echo $rss;
// CODE;
// echo phptotac($code);
parse_dir();
?>