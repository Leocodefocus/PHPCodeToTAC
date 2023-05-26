<?php
require_once '/home/leo/phpAVT-new/phpsa/vendor/autoload.php';
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class ThreeAddressCodeGenerator extends NodeVisitorAbstract {
    private $labelCounter = 0;
    private $tempVarCounter = 0;
    private $threeAddressCode = [];
    public function getThreeAddressCode() {
        return $this->threeAddressCode;
    }
    public function leaveNode(Node $node) {
        $result = [];
        if ($node instanceof Stmt\Function_) {
            $code = [];
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Stmt\If_) {
                    // 处理 if 语句
                    $cond = $this->handleExpr($stmt->cond);
                    $stmts = $this->generateStatements($stmt->stmts);
                    $elseStmts = isset($stmt->else) ? $this->generateStatements($stmt->else->stmts) : [];
                    $ifLabel = $this->createLabel();
                    $elseLabel = $this->createLabel();
                    $endLabel = $this->createLabel();
                    $code[] = "if " . $cond . " goto " . $ifLabel;
                    $code[] = "goto " . $elseLabel;
                    $code[] = $ifLabel . ":";
                    $code = array_merge($code, $stmts);
                    $code[] = "goto " . $endLabel;
                    $code[] = $elseLabel . ":";
                    $code = array_merge($code, $elseStmts);
                    $code[] = $endLabel . ":";
                    $result[] = ['label' => $ifLabel, 'code' => $code];
                } elseif ($stmt instanceof Stmt\While_) {
                    // 处理 while 循环
                    $condition = $this->handleExpr($stmt->cond);
                    $stmts = $this->generateStatements($stmt->stmts);
                    $loopLabel = $this->createLoopLabel();
                    $startLabel = $this->createLabel();
                    $code[] = "goto " . $startLabel['start'];
                    $code[] = $startLabel['start'] . ":";
                    if ($condition !== null) {
                        $code[] = "if " . $condition . " goto " . $loopLabel['start'];
                        $code[] = "goto " . $loopLabel['end'];
                    } else {
                        $code[] = "goto " . $loopLabel['start'];
                    }   
                    $code[] = $loopLabel['start'] . ":";
                    $code = array_merge($code, $stmts);
                    if ($condition !== null) {
                        $code[] = "if " . $condition . " goto " . $startLabel['start'];
                        $code[] = "goto " . $loopLabel['end'];
                    } else {
                        $code[] = "goto " . $startLabel['start'];
                    }
                    $code[] = $loopLabel['end'] . ":";
                    $result[] = ['label' => $startLabel['start'], 'code' => $code];
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
                    $result[] = ['label' => $loopLabel['start'], 'code' => $code];
                } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
                    $argVars = [];
                    foreach ($stmt->args as $arg) {
                        $argVars[] = $this->handleExpr($arg->value);
                    }
                    $tempVar = $this->createTempVar();
                    $code[] = $tempVar . " = call " . $stmt->name->name . "(" . implode(", ", $argVars) . ")\n";
                    $result[] = ['label' => $tempVar, 'code' => $code];
                }}   
            $code = [];
        }    
        return $result;
    }   
    private function createLabel() {
        return '_L' . $this->labelCounter++;}    
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
    $modifiedStmts = $traverser->traverse($stmts);
    // 6. 输出三地址码
    echo implode("\n", $threeAddressCodeGenerator->getThreeAddressCode());
} catch (Error $e) {
    echo 'Parse Error: ', $e->getMessage();}
?>
