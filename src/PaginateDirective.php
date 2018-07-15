<?php
namespace GQLExample;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Parser;

class PaginateDirective
{
    private $fieldInfoStack = [];

    private $generatedTypes = [];

    public function getVisitor()
    {
        return [
            'enter' => [
                NodeKind::FIELD_DEFINITION => function (FieldDefinitionNode $fieldNode, $key, $parent, $path, $ancestors) {
                    // Entering new field - add new entry in stack with following info:
                    // [
                    //     FieldDefinitionNode $fieldNode,
                    //     TypeDefinitionNode $parentNode,
                    //     ?int $paginateDirectiveIndex
                    // ]
                    // Last value of the stack always contains the field we are currently visiting
                    // Other node visitors may use it to get details about current field.

                    // Variable $parent contains parent NodeList, not type so using $ancestors to find parent type
                    $parentType = $ancestors[count($ancestors) - 1];
                    $this->fieldInfoStack[] = [$fieldNode, $parentType, null];
                },
                NodeKind::DIRECTIVE => function (DirectiveNode $directive, $index) {
                    // Directives may appear in different nodes
                    // Make sure this directive actually belongs to our current field (using fieldInfoStack):
                    //
                    // If it does - store it's index and generate all connection types
                    // We will append them to document during "leave" step
                    //
                    if ($this->isExpectedDirective($this->getField(), $index, $directive)) {
                        list ($field, $parent) = array_pop($this->fieldInfoStack);
                        $this->fieldInfoStack[] = [$field, $parent, $index];
                        $this->generatedTypes[] = $this->buildConnectionType($field, $parent);
                        $this->generatedTypes[] = $this->buildEdgeType($field, $parent);
                    }
                }
            ],
            'leave' => [
                NodeKind::FIELD_DEFINITION => function () {
                    // Leaving a field node.
                    // If field has "@paginate" directive we replace it's AST (otherwise - keeping node intact):
                    list ($field, $parent, $paginateDirectiveIndex) = array_pop($this->fieldInfoStack);
                    return isset($paginateDirectiveIndex) ?
                        $this->replaceFieldNode($field, $parent, $paginateDirectiveIndex) :
                        null;
                },
                NodeKind::DOCUMENT => function(DocumentNode $node) {
                    // Leaving a document.
                    // If we had new types generated during visiting - appending those to document definitions.
                    if (!empty($this->generatedTypes)) {
                        $tmp = clone $node;
                        $tmp->definitions = $node->definitions->merge($this->generatedTypes);
                        return $tmp;
                    }
                    // TODO:
                    // One issue though is that those new types are not visited (since we are already leaving).
                    // If some types must be visited - we can adjust the code to visit them
                    //
                    // Something along the lines:
                    // $nodeList = Visitor::visitInParallel(new NodeList($this->generatedTypes), $context->visitors);
                },
            ],
        ];
    }

    private function getField(): FieldDefinitionNode
    {
        // Current field is the last one in the stack
        return $this->fieldInfoStack[count($this->fieldInfoStack) - 1][0];
    }

    private function getFieldDirectiveAt(FieldDefinitionNode $field, int $index): ?DirectiveNode
    {
        return $field->directives[$index] ?? null;
    }

    private function isExpectedDirective(FieldDefinitionNode $field, $directiveIndex, DirectiveNode $testedDirective)
    {
        return $this->getFieldDirectiveAt($field, $directiveIndex) === $testedDirective &&
            $testedDirective->name->value === 'paginate';
    }

    private function buildConnectionType($field, $parent)
    {
        $type = <<<SDL
            type {$this->name($field, $parent)}Connection {
                pageInfo: PageInfo! @field(class: "ConnectionField" method: "pageInfoResolver")
                edges: [QueryUserEdge] @field(class: "ConnectionField" method: "edgeResolver")
            }
SDL;
        return Parser::parse($type)->definitions[0];
    }

    private function buildEdgeType($field, $parent)
    {
        $type = <<<SDL
            type {$this->name($field, $parent)}Edge {
                node: {$this->unpackNodeToString($field->type)}
                cursor: String!
            }
SDL;
        return Parser::parse($type)->definitions[0];
    }

    private function replaceFieldNode(FieldDefinitionNode $field, $parent, $paginateDirectiveIndex)
    {
        $paginateDirective = $this->getFieldDirectiveAt($field, $paginateDirectiveIndex);

        if (!$paginateDirective) {
            return $field;
        }

        $newField = clone $field;
        $newField->arguments = $field->arguments->merge([
            $this->argNode('first', 'Int!'),
            $this->argNode('after', 'String'),
        ]);
        $newField->type = Parser::parseType($this->name($field, $parent) . 'Connection');

        return $newField;
    }

    private function name($field, $parent)
    {
        return ucfirst($parent->name->value) . ucfirst($field->name->value);
    }

    private function argNode($name, $type)
    {
        return new ArgumentNode([
            'name' => new NameNode(['value' => $name]),
            'value' => Parser::parseType($type),
        ]);
    }

    private function unpackNodeToString(Node $node)
    {
        if (in_array($node->kind, ['ListType', 'NonNullType', 'FieldDefinition'])) {
            return $this->unpackNodeToString($node->type);
        }

        return $node->name->value;
    }
}
