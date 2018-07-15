# About
Quick example of `webonyx/graphql-php` [Visitor](http://webonyx.github.io/graphql-php/reference/#graphqllanguagevisitor)
usage. It transforms `@paginate` directive on type fields into Relay connection.

For example, following schema:
```graphqls
type User {
    id: ID!
}

type Query {
    users: [User!]! @paginate(type: "relay" model: "User")
}
```

will be converted into:
```
type User {
  id: ID!
}

type Query {
  users(first: Int!, after: String): QueryUsersConnection @paginate(type: "relay", model: "User")
}

type QueryUsersConnection {
  pageInfo: PageInfo! @field(class: "ConnectionField", method: "pageInfoResolver")
  edges: [QueryUserEdge] @field(class: "ConnectionField", method: "edgeResolver")
}

type QueryUsersEdge {
  node: User
  cursor: String!
}
```

## Installation
```
composer install
```

## Run Example
```
composer run-example
```

# Visitor Logic
Visitor collects required data when entering `field` and `directive` nodes.
Then it will apply transformations using this data when leaving `field` node 
and add new types when leaving `document` node.

# Visitor Code Excerpt
```php
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
```
Full code is available at [PaginateDirective.php](src/PaginateDirective.php) and [run.php](src/run.php) for usage.
