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
        return '_t' . $this->tempVarCounter++;
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
            $variableName = $expr->name instanceof PhpParser\Node\Identifier ? $expr->name->name : $expr->name;
            // 处理变量
            return [
                "var"=>"$" . $variableName,
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
            // 处理字符串
            return [
                "var"=>"'" . $expr->value . "'",
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
            $argVars = [];
            $tacs = [];
            foreach ($expr->args as $arg) {
                $argVarDict = $this->handleExpr($arg->value);
                $argVars[] = $argVarDict["var"];
                $tacs = array_merge($tacs,$argVarDict["tac"]);
            }
            $tempVar = $this->createTempVar();
            // $tac = new ThreeAddressInstruction("",$expr->name . "(" . implode(", ", $argVars) . ")","",$tempVar);
            $tacs[] = $tempVar . " = " . $expr->name . "(" . implode(", ", $argVars) . ");";
            // return $expr->name . "(" . implode(", ", $argVars) . ")";
            return [
                "var"=>$tempVar,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\MethodCall) {
            $this->processedNodes->attach($expr);
            $tacs = [];
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
            
            $tacs[] = $tempVar . " = " . $objectVar["var"] . "." . $expr->name->name . "(" . implode(", ", $argVars) . ");";
            return [
                "var"=>$tempVar,
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\PropertyFetch) {
            $this->processedNodes->attach($expr);
            $expDict = $this->handleExpr($expr->var);
            $tacs = [];
            $tacs = array_merge($tacs,$expDict["tac"]);
            // $tempVar = $this->createTempVar();
            // $tempVar = $tempVar . " = " . $this->handleExpr($expr->var) . "->" . $expr->name->name . "\n";
            // return $tempVar;
            return [
                "var"=>$expDict["var"] . "->" . $expr->name->name,
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
                "var"=>implode('', $parts),
                "tac"=>$tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\ClassConstFetch){
            $this->processedNodes->attach($expr);
            $tacs = [];
            // Assume that $this->handleName can convert a Name node to a string.
            $classNameDict = $this->handleName($expr->class);
            $tacs = array_merge($tacs,$classNameDict["tac"]);
            return [
                "var"=>"{$classNameDict["var"]}::{$expr->name}",
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
        } elseif ($expr instanceof PhpParser\Node\Expr\AssignOp\Concat) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $varDict = $this->handleExpr($expr->var);
            $exprDict = $this->handleExpr($expr->expr);
            //$tempVar = $this->createTempVar();
            //return $tempVar . " = " . $var . " . " . $expr;
            $tacs = array_merge($tacs,$varDict["tac"]);
            $tacs = array_merge($tacs,$exprDict["tac"]);
            return [
                "var"=>$varDict["var"] . " . " . $exprDict["var"],
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
        } elseif ($expr instanceof PhpParser\Node\Expr\Include_) {
            $this->processedNodes->attach($expr);
            $tacs = [];
            $exprDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($tacs, $exprDict["tac"]);
            
            if ($expr->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE) {
                $tacs[] = 'include ' . $exprDict["var"];
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE) {
                $tacs[] = 'include_once ' . $exprDict["var"];
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE) {
                $tacs[] = 'require ' . $exprDict["var"];
            } elseif ($expr->type === PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE) {
                $tacs[] = 'require_once ' . $exprDict["var"];
            }
            
            return [
                "var" => "",
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\AssignOp\Plus) {
            $this->processedNodes->attach($expr);
            $varDict = $this->handleExpr($expr->var);
            $exprDict = $this->handleExpr($expr->expr);
            $tacs = array_merge($varDict["tac"], $exprDict["tac"]);
            return [
                "var" => $varDict["var"] . ' += ' . $exprDict["var"],
                "tac" => $tacs
            ];
        } elseif ($expr instanceof PhpParser\Node\Expr\List_) {
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
        } else {
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
            $code[] = "if " . $conditionDict["var"] . " goto " . $ifLabel['start'] . ";";
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
            $code[] = "if " . $exprDict["var"] . " goto " . "Label for elseif branch;\n";
            // Recurse into "elseif" branch
        } elseif ($node instanceof PhpParser\Node\Stmt\Else_) {
            $this->processedNodes->attach($node);
            $code[] = "goto Label for else branch;\n";
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
            $code[] = "if " . $conditionDict["var"] . " goto " . $loopLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";    
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "if " . $conditionDict["var"] . " goto " . $startLabel['start'] . ";";
            $code[] = "goto " . $loopLabel['end'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\For_) {
            $this->processedNodes->attach($node);
            // 处理 for 循环
            $initStmts = $node->init !== null ? $this->generateStatements([$node->init]) : [];
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
            $loopStmts = $node->loop !== null ? $this->generateStatements([$node->loop]) : [];
            $stmts = $this->generateStatements($node->stmts);
            $startLabel = $this->createLabel();
            $loopLabel = $this->createLabel();
            if ($conditionDict !== null && !is_array($conditionDict)) {
                $code = array_merge($code, $conditionDict["tac"]);
            }
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
                        $code[] = "if " . $condition["var"] . " goto " . $conditionLabels[$index]['start'] . ";";
                        $code[] = "goto " . $loopLabel['end'] . ";";
                    }
                    $code[] = $conditionLabels[count($conditionDict) - 1]['end'] . ":";
                } else {
                    $code[] = "if " . $conditionDict["var"] . " goto " . $loopLabel['start'] . ";";
                    $code[] = "goto " . $loopLabel['end'] . ";";
                }
            } else {
                $code[] = "goto " . $loopLabel['start'] . ";";
            }
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code = array_merge($code, $loopStmts);
            if ($conditionDict !== null && !is_array($conditionDict)) {
                $code[] = "if " . $conditionDict["var"] . " goto " . $startLabel['start'] . ";";
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
            $code[] = "foreach " . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . " goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['start'] . ":";
            $code = array_merge($code, $stmts);
            $code[] = "foreach " . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . " goto " . $loopLabel['start'] . ";";
            $code[] = $loopLabel['end'] . ":";
        } elseif ($node instanceof PhpParser\Node\Stmt\Class_) {
            $this->processedNodes->attach($node);
            // 处理类定义
            $className = $this->handleName($node->name);
            $code[] = "class " . $className . "{";
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
            $code[] = $tempVar . " = " . $objectVarDict["var"] . "." . $node->name->name . "(" . implode(", ", $argVars) . ")";
        } elseif ($node instanceof PhpParser\Node\Expr\FuncCall) {
            $this->processedNodes->attach($node);
            $name = $node->name->toString();
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
        } 
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
            if($node instanceof PhpParser\Node\Stmt\Expression){
                $code[] = ";\n";
            }
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
                $code[] = "if " . $conditionDict["var"] . " goto " . $ifLabel['start'] . ";";
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
            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $this->processedNodes->attach($stmt);
                // 处理 while 循环
                $conditionDict = $this->handleExpr($stmt->cond);
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                $code = array_merge($code,$conditionDict["tac"]);
                $code[] = $startLabel['start'] . ":";
                $code[] = "if " . $conditionDict["var"] . " goto " . $loopLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code[] = "if " . $conditionDict["var"] . " goto " . $startLabel['start'] . ";";
                $code[] = "goto " . $loopLabel['end'] . ";";
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $startLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $this->processedNodes->attach($stmt);
                // 处理 for 循环
                $initStmts = $stmt->init !== null ? $this->generateStatements([$stmt->init]) : [];
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
                $loopStmts = $stmt->loop !== null ? $this->generateStatements([$stmt->loop]) : [];
                $stmts = $this->generateStatements($stmt->stmts);
                $startLabel = $this->createLabel();
                $loopLabel = $this->createLabel();
                if ($conditionDict !== null && !is_array($conditionDict)) {
                    $code = array_merge($code, $conditionDict["tac"]);
                }
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
                            $code[] = "if " . $condition["var"] . " goto " . $conditionLabels[$index]['start'] . ";";
                            $code[] = "goto " . $loopLabel['end'] . ";";
                        }
                        $code[] = $conditionLabels[count($conditionDict) - 1]['end'] . ":";
                    } else {
                        $code[] = "if " . $conditionDict["var"] . " goto " . $loopLabel['start'] . ";";
                        $code[] = "goto " . $loopLabel['end'] . ";";
                    }
                } else {
                    $code[] = "goto " . $loopLabel['start'] . ";";
                }
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                $code = array_merge($code, $loopStmts);
                if ($conditionDict !== null && !is_array($conditionDict)) {
                    $code[] = "if " . $conditionDict["var"] . " goto " . $startLabel['start'] . ";";
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
                $code[] = "foreach " . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . " goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['start'] . ":";
                $code = array_merge($code, $stmts);
                //$code[] = "foreach " . $arrayVarDict["var"] . " as " . $valueVarDict["var"] . " goto " . $loopLabel['start'] . ";";  
                $code[] = $loopLabel['end'] . ":";
                // $result[] = ['label' => $loopLabel['start'], 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
                $this->processedNodes->attach($stmt);
                $argVars = [];
                foreach ($stmt->args as $arg) {
                    $argVarDict = $this->handleExpr($arg->value);
                    $code = array_merge($code,$argVarDict["tac"]);
                    $argVars[] = $argVarDict["var"];
                }
                $tempVar = $this->createTempVar();
                $code[] = $tempVar . " = call " . $stmt->name->name . "(" . implode(", ", $argVars) . ")";
                // $result[] = ['label' => $tempVar, 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                $this->processedNodes->attach($stmt);
                // 处理类定义
                $className = $stmt->name;
                $code[] = "class " . $className . "{";
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
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_function " . $functionName;
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
                    $code = array_merge($code, $this->generateStatements([$stmt->finally->stmts]));
                }
                $code[] = "end_try";
                // $result[] = ['label' => 'try_catch', 'code' => $code];
                // $result = array_merge($result, $code);
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Catch_) {
                $this->processedNodes->attach($stmt);
                // 处理 catch
                $code[] = "catch " . $stmt->varType . " as " . $stmt->var->name;
                foreach ($stmt->stmts as $innerStmt) {
                    $code = array_merge($code, $this->generateStatements([$innerStmt]));
                }
                $code[] = "end_catch";
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
                // 处理属性定义
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->name;
                    $defaultValueDict = $prop->default !== null ? $this->handleExpr($prop->default) : null;
                    if ($defaultValueDict !== null){
                        $code = array_merge($code,$defaultValueDict["tac"]);
                        $code[] = "var $" . $propertyName . " = " . $defaultValueDict["var"] . ";";
                    } else{
                        $code[] = "var $" . $propertyName . " = " . $defaultValueDict . ";";
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
                $code[] = "( " . implode(", ",$params) . " ){"; 
    
                $code = array_merge($code, $this->generateStatements($stmt->stmts));
    
                $code[] = "}";
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
                $code[] = $varName . ' = ' . $varName . ' + 1';
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression) {
                $this->processedNodes->attach($stmt);
                // 处理表达式语句
                $exprDict = $this->handleExpr($stmt->expr);
                $code = array_merge($code,$exprDict["tac"]);
                // $tempVar = $this->createTempVar();
                $code[] = $exprDict["var"];
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

function phptotac($file_path){
    global $parser;
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
        $final_code = implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
        $final_code = str_replace("\n;",";",$final_code);
        $final_code = preg_replace('/(.+)(\n;)/','${1};',$final_code);
        $final_code = preg_replace('/^_t\d{1,10};\n/m','',$final_code);
        $final_code = preg_replace('/;+$/m',';',$final_code);
        $final_code = preg_replace('/^;\n$/m','',$final_code);
        $final_code = preg_replace('/^};\n$/m','}',$final_code);
        /* echo "<?php\n" . $final_code . "\n?>";
        echo implode("\n",$modifiedStmts);*/
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
        // 调用你的转换函数
        $convertedCode = phptotac($file->getPathname());

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
// 调用函数
convertPhpToTac('/home/leo/phpAVT-new/php_src/SQLi/CVE-2021-27973-piwigo-11_3_0-SQLi', '/home/leo/phpAVT-new/php_src/SQLi/CVE-2021-27973-piwigo-11_3_0-SQLi_TAC');
?>