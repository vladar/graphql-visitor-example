<?php
namespace GQLExample;

use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Language\Visitor;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . './PaginateDirective.php';

$sdl = file_get_contents(__DIR__ . './example.graphqls');
$ast = Parser::parse($sdl, ['noLocation' => true]);
$directive = new PaginateDirective();

// Allow multiple visitors to work in parallel while traversing the AST
$visitors = Visitor::visitInParallel([
    $directive->getVisitor(),
    // ...other visitors
]);
$modifiedAst = Visitor::visit($ast, $visitors);

echo Printer::doPrint($modifiedAst);
