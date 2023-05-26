<?php
require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class NodeDumper extends NodeVisitorAbstract {
    public function leaveNode(Node $node) {
        echo get_class($node), "\n";
    }
}

$code = file_get_contents('/home/leo/phpAVT-new/code_examples.php');

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

try {
    $stmts = $parser->parse($code);

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NodeDumper);

    $stmts = $traverser->traverse($stmts);
} catch (Error $e) {
    echo 'Parse Error: ', $e->getMessage();
}
?>