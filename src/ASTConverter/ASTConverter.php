<?php declare(strict_types=1);
namespace ASTConverter;

use ast;
use PhpParser;
use PhpParser\ParserFactory;

/**
 * Source: https://github.com/TysonAndre/php-parser-to-php-ast
 * Uses PhpParser to create an instance of ast\Node.
 * Useful if the php-ast extension isn't actually installed.
 * @author Tyson Andre
 */
class ASTConverter {
    // The latest stable version of php-ast.
    // For something > 50, update the library's release.
    // For something < 40, there are no releases.
    const AST_VERSION = 50;
    // The versions that this supports
    const SUPPORTED_AST_VERSIONS = [40, 50];

    /**
     * @var int - A version in SUPPORTED_AST_VERSIONS
     */
    private static $ast_version = self::AST_VERSION;

    /**
     * @var int - A version in SUPPORTED_AST_VERSIONS
     */
    private static $decl_id = 0;

    private static $should_add_placeholders = false;

    public static function setShouldAddPlaceholders(bool $value) : void {
        self::$should_add_placeholders = $value;
    }

    public static function astParseCodeFallback(string $source, int $version, bool $suppress_errors = false, array &$errors = null) {
        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %d, got %d", implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }
        // Aside: this can be implemented as a stub.
        $parser_node = self::phpparserParse($source, $suppress_errors, $errors);
        return self::phpparserToPhpast($parser_node, $version);
    }

    /**
     * @return PhpParser\Node\Stmt[]|null
     */
    public static function phpparserParse(string $source, bool $suppress_errors = false, array &$errors = null) {
        $parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7);
        $error_handler = $suppress_errors ? new PhpParser\ErrorHandler\Collecting() : null;
        // $node_dumper = new PhpParser\NodeDumper();
        $result = $parser->parse($source, $error_handler);
        if ($suppress_errors) {
            $errors = $error_handler->getErrors();
        }
        return $result;
    }


    /**
     * @param PhpParser\Node|PhpParser\Node[] $parser_node
     * @param int $version
     * @return ast\Node
     */
    public static function phpparserToPhpast($parser_node, int $version) {
        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }
        self::startParsing($version);
        $stmts = self::phpparserStmtlistToAstNode($parser_node, 1);
        // return self::normalizeNamespaces($stmts);
        return $stmts;
    }

    private static function startParsing(int $ast_version) {
        self::$ast_version = $ast_version;
        self::$decl_id = 0;
    }

    private static function phpparserStmtlistToAstNode(array $parser_nodes, ?int $lineno) : ast\Node {
        $stmts = new ast\Node();
        $stmts->kind = ast\AST_STMT_LIST;
        $stmts->flags = 0;
        $children = [];
        foreach ($parser_nodes as $parser_node) {
            $child_node = self::phpparserNodeToAstNode($parser_node);
            if (is_array($child_node)) {
                // Echo_ returns multiple children.
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } else if (!is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        if (!is_int($lineno)) {
            foreach ($parser_nodes as $parser_node) {
                $child_node_line = sl($parser_node);
                if ($child_node_line > 0) {
                    $lineno = $child_node_line;
                    break;
                }
            }
        }
        $stmts->lineno = $lineno ?? 0;
        $stmts->children = $children;
        return $stmts;
    }

    /**
    // Convert this into the most common way to write a namespace node.
    private static function normalizeNamespaces(ast\Node $stmts_node) : ast\Node {
        $has_namespace = false;
        foreach ($stmts_node->children as $stmt) {
            if ($stmt->kind !== \ast\AST_NAMESPACE) {
                continue;
            }
            $has_namespace = true;
            if ($stmt->children['name'] === null) {
                return $stmts_node;
            }
        }
        if ($has_namespace === false) {
            return $stmts_node;
        }
        $new_node = clone($stmts_node);
        $children = [];
        foreach ($stmts_node->children as $stmt) {
            if ($stmt->kind == \ast\AST_NAMESPACE) {
            }
        }
    }
     */

    /**
     * @param PHPParser\Node[] $exprs
     */
    private static function phpparserExprListToExprList(array $exprs, int $lineno) : ast\Node {
        $children = [];
        foreach ($exprs as $expr) {
            $child_node = self::phpparserNodeToAstNode($expr);
            if (is_array($child_node)) {
                // Echo_ returns multiple children.
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } else if (!is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        foreach ($exprs as $parser_node) {
            $child_node_line = sl($parser_node);
            if ($child_node_line > 0) {
                $lineno = $child_node_line;
                break;
            }
        }
        return new ast\Node(
            ast\AST_EXPR_LIST,
            0,
            $children,
            $lineno
        );
    }

    /**
     * @param PhpParser\Node $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     */
    private static final function phpparserNodeToAstNode($n) {
        if (!($n instanceof PhpParser\Node)) {
            //debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            throw new \InvalidArgumentException("Invalid type for node: " . (is_object($n) ? get_class($n) : gettype($n)));
        }

        static $callback_map;
        static $fallback_closure;
        if (\is_null($callback_map)) {
            $callback_map = self::initHandleMap();
            $fallback_closure = function(\PHPParser\Node $n, int $start_line) {
                return self::astStub($n);
            };
        }
        $callback = $callback_map[get_class($n)] ?? $fallback_closure;
        return $callback($n, $n->getAttribute('startLine') ?: 0);
    }

    /**
     * This returns an array of values mapping class names to the closures which converts them to a scalar or ast\Node or ast\Node\Decl
     *
     * Why not a switch? Switches are slow until php 7.2, and there are dozens of class names to handle.
     *
     * - In php <= 7.1, the interpreter would loop through all possible cases, and compare against the value one by one.
     * - There are a lot of local variables to look at.
     *
     * @return \Closure[]
     */
    private static function initHandleMap() : array {
        $closures = [
            'PhpParser\Node\Arg'                            => function(PhpParser\Node\Arg $n, int $start_line) {
                $result = self::phpparserNodeToAstNode($n->value);
                if ($n->unpack) {
                    return new ast\Node(
                        \ast\AST_UNPACK,
                        0,
                        ['expr' => $result],
                        $start_line
                    );
                }
                return $result;
            },
            'PhpParser\Node\Expr\Array_'                    => function(PhpParser\Node\Expr\Array_ $n, int $start_line) : ast\Node {
                return self::phpparserArrayToAstArray($n, $start_line);
            },
            'PhpParser\Node\Expr\ArrayDimFetch'            => function(PhpParser\Node\Expr\ArrayDimFetch $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_DIM, 0, [
                    'expr' => self::phpparserNodeToAstNode($n->var),
                    'dim' => $n->dim !== null ? self::phpparserNodeToAstNode($n->dim) : null,
                ], $start_line);
            },
            'PhpParser\Node\Expr\Assign'                    => function(PhpParser\Node\Expr\Assign $n, int $start_line) : ?ast\Node {
                return self::astNodeAssign(
                    self::phpparserNodeToAstNode($n->var),
                    self::phpparserNodeToAstNode($n->expr),
                    $start_line,
                    false
                );
            },
            'PhpParser\Node\Expr\AssignRef'                 => function(PhpParser\Node\Expr\AssignRef $n, int $start_line) : ?ast\Node {
                return self::astNodeAssign(
                    self::phpparserNodeToAstNode($n->var),
                    self::phpparserNodeToAstNode($n->expr),
                    $start_line,
                    true
                );
            },
            'PhpParser\Node\Expr\AssignOp\BitwiseAnd'       => function(PhpParser\Node\Expr\AssignOp\BitwiseAnd $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_BITWISE_AND, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\BitwiseXor'       => function(PhpParser\Node\Expr\AssignOp\BitwiseXor $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_BITWISE_XOR, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\BitwiseOr'        => function(PhpParser\Node\Expr\AssignOp\BitwiseOr $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_BITWISE_OR, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Concat'           => function(PhpParser\Node\Expr\AssignOp\Concat $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_CONCAT, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Div'           => function(PhpParser\Node\Expr\AssignOp\Div $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_DIV, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Mod'           => function(PhpParser\Node\Expr\AssignOp\Mod $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_MOD, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Mul'           => function(PhpParser\Node\Expr\AssignOp\Mul $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_MUL, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Minus'           => function(PhpParser\Node\Expr\AssignOp\Minus $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_SUB, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Plus'           => function(PhpParser\Node\Expr\AssignOp\Plus $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_ADD, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\Pow'           => function(PhpParser\Node\Expr\AssignOp\Pow $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_POW, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\ShiftLeft'           => function(PhpParser\Node\Expr\AssignOp\ShiftLeft $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_SHIFT_LEFT, $n, $start_line);
            },
            'PhpParser\Node\Expr\AssignOp\ShiftRight'           => function(PhpParser\Node\Expr\AssignOp\ShiftRight $n, int $start_line) : ast\Node {
                return self::astNodeAssignop(ast\flags\BINARY_SHIFT_RIGHT, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\BitwiseAnd' => function(PhpParser\Node\Expr\BinaryOp\BitwiseAnd $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BITWISE_AND, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\BitwiseOr' => function(PhpParser\Node\Expr\BinaryOp\BitwiseOr $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BITWISE_OR, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\BitwiseXor' => function(PhpParser\Node\Expr\BinaryOp\BitwiseXor $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BITWISE_XOR, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\BooleanAnd' => function(PhpParser\Node\Expr\BinaryOp\BooleanAnd $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BOOL_AND, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\BooleanOr' => function(PhpParser\Node\Expr\BinaryOp\BooleanOr $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BOOL_OR, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Coalesce' => function(PhpParser\Node\Expr\BinaryOp\Coalesce $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_COALESCE, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Concat' => function(PhpParser\Node\Expr\BinaryOp\Concat $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_CONCAT, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Div' => function(PhpParser\Node\Expr\BinaryOp\Div $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_DIV, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Equal' => function(PhpParser\Node\Expr\BinaryOp\Equal $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_EQUAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Greater' => function(PhpParser\Node\Expr\BinaryOp\Greater $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_GREATER, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\GreaterOrEqual' => function(PhpParser\Node\Expr\BinaryOp\GreaterOrEqual $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_GREATER_OR_EQUAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Identical' => function(PhpParser\Node\Expr\BinaryOp\Identical $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_IDENTICAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\LogicalAnd' => function(PhpParser\Node\Expr\BinaryOp\LogicalAnd $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BOOL_AND, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\LogicalOr' => function(PhpParser\Node\Expr\BinaryOp\LogicalOr $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BOOL_OR, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\LogicalXor' => function(PhpParser\Node\Expr\BinaryOp\LogicalXor $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_BOOL_XOR, $n, $start_line);
            },
            // FIXME: rest of binary operations.
            'PhpParser\Node\Expr\BinaryOp\Minus' => function(PhpParser\Node\Expr\BinaryOp\Minus $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_SUB, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Mod' => function(PhpParser\Node\Expr\BinaryOp\Mod $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_MOD, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Mul' => function(PhpParser\Node\Expr\BinaryOp\Mul $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_MUL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\NotEqual' => function(PhpParser\Node\Expr\BinaryOp\NotEqual $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_NOT_EQUAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\NotIdentical' => function(PhpParser\Node\Expr\BinaryOp\NotIdentical $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_NOT_IDENTICAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Plus' => function(PhpParser\Node\Expr\BinaryOp\Plus $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_ADD, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Pow' => function(PhpParser\Node\Expr\BinaryOp\Pow $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_POW, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\ShiftLeft' => function(PhpParser\Node\Expr\BinaryOp\ShiftLeft $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_SHIFT_LEFT, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\ShiftRight' => function(PhpParser\Node\Expr\BinaryOp\ShiftRight $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_SHIFT_RIGHT, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Smaller' => function(PhpParser\Node\Expr\BinaryOp\Smaller $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_SMALLER, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\SmallerOrEqual' => function(PhpParser\Node\Expr\BinaryOp\SmallerOrEqual $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_IS_SMALLER_OR_EQUAL, $n, $start_line);
            },
            'PhpParser\Node\Expr\BinaryOp\Spaceship' => function(PhpParser\Node\Expr\BinaryOp\Spaceship $n, int $start_line) : ast\Node {
                return self::astNodeBinaryop(ast\flags\BINARY_SPACESHIP, $n, $start_line);
            },
            'PhpParser\Node\Expr\BitwiseNot' => function(PhpParser\Node\Expr\BitwiseNot $n, int $start_line) : ast\Node {
                return self::astNodeUnaryOp(ast\flags\UNARY_BITWISE_NOT, self::phpparserNodeToAstNode($n->expr), $start_line);
            },
            'PhpParser\Node\Expr\BooleanNot' => function(PhpParser\Node\Expr\BooleanNot $n, int $start_line) : ast\Node {
                return self::astNodeUnaryOp(ast\flags\UNARY_BOOL_NOT, self::phpparserNodeToAstNode($n->expr), $start_line);
            },
            'PhpParser\Node\Expr\Cast\Array_' => function(PhpParser\Node\Expr\Cast\Array_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_ARRAY, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\Bool_' => function(PhpParser\Node\Expr\Cast\Bool_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_BOOL, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\Double' => function(PhpParser\Node\Expr\Cast\Double $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_DOUBLE, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\Int_' => function(PhpParser\Node\Expr\Cast\Int_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_LONG, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\Object_' => function(PhpParser\Node\Expr\Cast\Object_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_OBJECT, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\String_' => function(PhpParser\Node\Expr\Cast\String_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_STRING, $n, $start_line);
            },
            'PhpParser\Node\Expr\Cast\Unset_' => function(PhpParser\Node\Expr\Cast\Unset_ $n, int $start_line) : ast\Node {
                return self::astNodeCast(ast\flags\TYPE_NULL, $n, $start_line);
            },
            'PhpParser\Node\Expr\Closure' => function(PhpParser\Node\Expr\Closure $n, int $start_line) : ast\Node {
                // FIXME: iterate to parent statement searching for the comments
                $comments = $n->getAttribute('comments');
                // TODO: is there a corresponding flag for $n->static? $n->byRef?
                return self::astDeclClosure(
                    $n->byRef,
                    $n->static,
                    self::phpparserParamsToAstParams($n->params, $start_line),
                    self::phpparserClosureUsesToAstClosureUses($n->uses, $start_line),
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    self::phpparserTypeToAstNode($n->returnType, sl($n->returnType) ?: $start_line),
                    $start_line,
                    $n->getAttribute('endLine'),
                    self::extractPhpdocComment($comments)
                );
                // FIXME: add a test of ClassConstFetch to php-ast
            },
            'PhpParser\Node\Expr\ClassConstFetch' => function(PhpParser\Node\Expr\ClassConstFetch $n, int $start_line) : ?ast\Node {
                return self::phpparserClassconstfetchToAstClassconstfetch($n, $start_line);
            },
            'PhpParser\Node\Expr\Clone_' => function(PhpParser\Node\Expr\Clone_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_CLONE, 0, ['expr' => self::phpparserNodeToAstNode($n->expr)], $start_line);
            },
            'PhpParser\Node\Expr\ConstFetch' => function(PhpParser\Node\Expr\ConstFetch $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_CONST, 0, ['name' => self::phpparserNodeToAstNode($n->name)], $start_line);
            },
            'PhpParser\Node\Expr\ErrorSuppress' => function(PhpParser\Node\Expr\ErrorSuppress $n, int $start_line) : ast\Node {
                return self::astNodeUnaryOp(ast\flags\UNARY_SILENCE, self::phpparserNodeToAstNode($n->expr), $start_line);
            },
            'PhpParser\Node\Expr\Empty_' => function(PhpParser\Node\Expr\Empty_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_EMPTY, 0, ['expr' => self::phpparserNodeToAstNode($n->expr)], $start_line);
            },
            'PhpParser\Node\Expr\Eval_' => function(PhpParser\Node\Expr\Eval_ $n, int $start_line) : ast\Node {
                return self::astNodeEval(
                    self::phpparserNodeToAstNode($n->expr),
                    $start_line
                );
            },
            'PhpParser\Node\Expr\Error' => function(PhpParser\Node\Expr\Error $n, int $start_line) {
                // This is where PhpParser couldn't parse a node.
                // TODO: handle this.
                return null;
            },
            'PhpParser\Node\Expr\Exit_' => function(PhpParser\Node\Expr\Exit_ $n, int $start_line) {
                return new ast\Node(ast\AST_EXIT, 0, ['expr' => $n->expr ? self::phpparserNodeToAstNode($n->expr) : null], $start_line);
            },
            'PhpParser\Node\Expr\FuncCall' => function(PhpParser\Node\Expr\FuncCall $n, int $start_line) : ast\Node {
                return self::astNodeCall(
                    self::phpparserNodeToAstNode($n->name),
                    self::phpparserArgListToAstArgList($n->args, $start_line),
                    $start_line
                );
            },
            'PhpParser\Node\Expr\Include_' => function(PhpParser\Node\Expr\Include_ $n, int $start_line) : ast\Node {
                return self::astNodeInclude(
                    self::phpparserNodeToAstNode($n->expr),
                    $start_line,
                    $n->type
                );
            },
            'PhpParser\Node\Expr\Instanceof_' => function(PhpParser\Node\Expr\Instanceof_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_INSTANCEOF, 0, [
                    'expr'  => self::phpparserNodeToAstNode($n->expr),
                    'class' => self::phpparserNodeToAstNode($n->class),
                ], $start_line);
            },
            'PhpParser\Node\Expr\Isset_' => function(PhpParser\Node\Expr\Isset_ $n, int $start_line) : ast\Node {
                $ast_issets = [];
                foreach ($n->vars as $var) {
                    $ast_issets[] = new ast\Node(ast\AST_ISSET, 0, [
                        'var' => self::phpparserNodeToAstNode($var),
                    ], $start_line);
                }
                $e = $ast_issets[0];
                for ($i = 1; $i < \count($ast_issets); $i++) {
                    $right = $ast_issets[$i];
                    $e = new ast\Node(
                        ast\AST_BINARY_OP,
                        ast\flags\BINARY_BOOL_AND,
                        [
                            'left' => $e,
                            'right' => $right,
                        ],
                        $e->lineno
                    );
                }
                return $e;
            },
            'PhpParser\Node\Expr\List_' => function(PhpParser\Node\Expr\List_ $n, int $start_line) : ast\Node {
                return self::phpparserListToAstList($n, $start_line);
            },
            'PhpParser\Node\Expr\MethodCall' => function(PhpParser\Node\Expr\MethodCall $n, int $start_line) : ast\Node {
                return self::astNodeMethodCall(
                    self::phpparserNodeToAstNode($n->var),
                    is_string($n->name) ? $n->name : self::phpparserNodeToAstNode($n->name),
                    self::phpparserArgListToAstArgList($n->args, $start_line),
                    $start_line
                );
            },
            'PhpParser\Node\Expr\New_' => function(PhpParser\Node\Expr\New_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_NEW, 0, [
                    'class' => self::phpparserNodeToAstNode($n->class),
                    'args' => self::phpparserArgListToAstArgList($n->args, $start_line),
                ], $start_line);
            },
            'PhpParser\Node\Expr\PreInc' => function(PhpParser\Node\Expr\PreInc $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_PRE_INC, 0, ['var' => self::phpparserNodeToAstNode($n->var)], $start_line);
            },
            'PhpParser\Node\Expr\PreDec' => function(PhpParser\Node\Expr\PreDec $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_PRE_DEC, 0, ['var' => self::phpparserNodeToAstNode($n->var)], $start_line);
            },
            'PhpParser\Node\Expr\PostInc' => function(PhpParser\Node\Expr\PostInc $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_POST_INC, 0, ['var' => self::phpparserNodeToAstNode($n->var)], $start_line);
            },
            'PhpParser\Node\Expr\PostDec' => function(PhpParser\Node\Expr\PostDec $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_POST_DEC, 0, ['var' => self::phpparserNodeToAstNode($n->var)], $start_line);
            },
            'PhpParser\Node\Expr\Print_' => function(PhpParser\Node\Expr\Print_ $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_PRINT,
                    0,
                    ['expr' => self::phpparserNodeToAstNode($n->expr)],
                    $start_line
                );
            },
            'PhpParser\Node\Expr\PropertyFetch' => function(PhpParser\Node\Expr\PropertyFetch $n, int $start_line) : ?ast\Node {
                return self::phpparserPropertyfetchToAstProp($n, $start_line);
            },
            'PhpParser\Node\Expr\ShellExec' => function(PhpParser\Node\Expr\ShellExec $n, int $start_line) : ast\Node {
                $parts = $n->parts;
                if (\count($parts) === 1 && $parts[0] instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
                    $value = $parts[0]->value;
                } else {
                    $value_inner = array_map(function(PhpParser\Node $node) { return self::phpparserNodeToAstNode($node); }, $parts);
                    $value = new ast\Node(ast\AST_ENCAPS_LIST, 0, $value_inner, $start_line);
                }
                return new ast\Node(ast\AST_SHELL_EXEC, 0, ['expr' => $value], $start_line);
            },
            'PhpParser\Node\Expr\StaticCall' => function(PhpParser\Node\Expr\StaticCall $n, int $start_line) : ast\Node {
                return self::astNodeStaticCall(
                    self::phpparserNodeToAstNode($n->class),
                    is_string($n->name) ? $n->name : self::phpparserNodeToAstNode($n->name),
                    self::phpparserArgListToAstArgList($n->args, $start_line),
                    $start_line
                );
            },
            'PhpParser\Node\Expr\StaticPropertyFetch' => function(PhpParser\Node\Expr\StaticPropertyFetch $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_STATIC_PROP,
                    0,
                    [
                        'class' => self::phpparserNodeToAstNode($n->class),
                        'prop' => is_string($n->name) ? $n->name : self::phpparserNodeToAstNode($n->name),
                    ],
                    $start_line
                );
            },
            'PhpParser\Node\Expr\Ternary' => function(PhpParser\Node\Expr\Ternary $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_CONDITIONAL,
                    0,
                    [
                        'cond' => self::phpparserNodeToAstNode($n->cond),
                        'true' => $n->if !== null ? self::phpparserNodeToAstNode($n->if) : null,
                        'false' => self::phpparserNodeToAstNode($n->else),
                    ],
                    $start_line
                );
            },
            'PhpParser\Node\Expr\UnaryMinus' => function(PhpParser\Node\Expr\UnaryMinus $n, int $start_line) : ast\Node {
                return self::astNodeUnaryOp(ast\flags\UNARY_MINUS, self::phpparserNodeToAstNode($n->expr), $start_line);
            },
            'PhpParser\Node\Expr\UnaryPlus' => function(PhpParser\Node\Expr\UnaryPlus $n, int $start_line) : ast\Node {
                return self::astNodeUnaryOp(ast\flags\UNARY_PLUS, self::phpparserNodeToAstNode($n->expr), $start_line);
            },
            'PhpParser\Node\Expr\Variable' => function(PhpParser\Node\Expr\Variable $n, int $start_line) : ?ast\Node {
                return self::astNodeVariable($n->name, $start_line);
            },
            'PhpParser\Node\Expr\Yield_' => function(PhpParser\Node\Expr\Yield_ $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_YIELD,
                    0,
                    [
                        'value' => $n->value !== null ? self::phpparserNodeToAstNode($n->value) : null,
                        'key'   => $n->key   !== null ? self::phpparserNodeToAstNode($n->key) : null,
                    ],
                    $start_line
                );
            },
            'PhpParser\Node\Expr\YieldFrom' => function(PhpParser\Node\Expr\YieldFrom $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_YIELD_FROM,
                    0,
                    ['expr' => self::phpparserNodeToAstNode($n->expr)],
                    $start_line
                );
            },
            'PhpParser\Node\Name' => function(PhpParser\Node\Name $n, int $start_line) : ast\Node {
                $name = implode('\\', $n->parts);
                return new ast\Node(ast\AST_NAME, ast\flags\NAME_NOT_FQ, ['name' => $name], $start_line);
            },
            'PhpParser\Node\Name\Relative' => function(PhpParser\Node\Name\Relative $n, int $start_line) : ast\Node {
                $name = implode('\\', $n->parts);
                return new ast\Node(ast\AST_NAME, ast\flags\NAME_RELATIVE, ['name' => $name], $start_line);
            },
            'PhpParser\Node\Name\FullyQualified' => function(PhpParser\Node\Name\FullyQualified $n, int $start_line) : ast\Node {
                $name = implode('\\', $n->parts);
                return new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $name], $start_line);
            },
            'PhpParser\Node\NullableType' => function(PhpParser\Node\NullableType $n, int $start_line) : ast\Node {
                return self::astNodeNullableType(
                    self::phpparserTypeToAstNode($n->type, $start_line),
                    $start_line
                );
            },
            'PhpParser\Node\Param' => function(PhpParser\Node\Param $n, int $start_line) : ast\Node {
                $type_line = sl($n->type) ?: $start_line;
                $default_line = sl($n->default) ?: $type_line;
                return self::astNodeParam(
                    $n->byRef,
                    $n->variadic,
                    self::phpparserTypeToAstNode($n->type, $type_line),
                    $n->name,
                    self::phpparserTypeToAstNode($n->default, $default_line),
                    $start_line
                );
            },
            'PhpParser\Node\Scalar\Encapsed' => function(PhpParser\Node\Scalar\Encapsed $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_ENCAPS_LIST,
                    0,
                    array_map(function(PhpParser\Node $n) { return self::phpparserNodeToAstNode($n); }, $n->parts),
                    $start_line
                );
            },
            'PhpParser\Node\Scalar\EncapsedStringPart' => function(PhpParser\Node\Scalar\EncapsedStringPart $n, int $start_line) : string {
                return $n->value;
            },
            'PhpParser\Node\Scalar\DNumber' => function(PhpParser\Node\Scalar\DNumber $n, int $start_line) : float {
                return (float)$n->value;
            },
            'PhpParser\Node\Scalar\LNumber' => function(PhpParser\Node\Scalar\LNumber $n, int $start_line) : int {
                return (int)$n->value;
            },
            'PhpParser\Node\Scalar\String_' => function(PhpParser\Node\Scalar\String_ $n, int $start_line) : string {
                return (string)$n->value;
            },
            'PhpParser\Node\Scalar\MagicConst\Class_' => function(PhpParser\Node\Scalar\MagicConst\Class_ $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_CLASS, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Dir' => function(PhpParser\Node\Scalar\MagicConst\Dir $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_DIR, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\File' => function(PhpParser\Node\Scalar\MagicConst\File $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_FILE, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Function_' => function(PhpParser\Node\Scalar\MagicConst\Function_ $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_FUNCTION, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Line' => function(PhpParser\Node\Scalar\MagicConst\Line $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_LINE, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Method' => function(PhpParser\Node\Scalar\MagicConst\Method $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_METHOD, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Namespace_' => function(PhpParser\Node\Scalar\MagicConst\Namespace_ $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_NAMESPACE, $start_line);
            },
            'PhpParser\Node\Scalar\MagicConst\Trait_' => function(PhpParser\Node\Scalar\MagicConst\Trait_ $n, int $start_line) : ast\Node {
                return self::astMagicConst(ast\flags\MAGIC_TRAIT, $start_line);
            },
            'PhpParser\Node\Stmt\Break_' => function(PhpParser\Node\Stmt\Break_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_BREAK, 0, ['depth' => isset($n->num) ? self::phpparserNodeToAstNode($n->num) : null], $start_line);
            },
            'PhpParser\Node\Stmt\Catch_' => function(PhpParser\Node\Stmt\Catch_ $n, int $start_line) : ast\Node {
                return self::astStmtCatch(
                    self::phpparserNameListToAstNameList($n->types, $start_line),
                    $n->var,
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\Class_' => function(PhpParser\Node\Stmt\Class_ $n, int $start_line) : ast\Node {
                $end_line = $n->getAttribute('endLine') ?: $start_line;
                return self::astStmtClass(
                    self::phpparserClassFlagsToAstClassFlags($n->flags),
                    $n->name,
                    $n->extends ? self::phpparserNodeToAstNode($n->extends) : null,
                    $n->implements,
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line,
                    $end_line,
                    self::extractPhpdocComment($n->getAttribute('comments'))
                );
            },
            'PhpParser\Node\Stmt\ClassConst' => function(PhpParser\Node\Stmt\ClassConst $n, int $start_line) : ast\Node {
                return self::phpparserClassConstToAstNode($n, $start_line);
            },
            'PhpParser\Node\Stmt\ClassMethod' => function(PhpParser\Node\Stmt\ClassMethod $n, int $start_line) : ast\Node {
                $flags = self::phpparserVisibilityToAstVisibility($n->flags);
                $stmts = is_array($n->stmts) ? self::phpparserStmtlistToAstNode($n->stmts, $start_line) : null;
                if ($n->byRef) {
                     $flags |= ast\flags\RETURNS_REF;
                }
                /*
                if (PHP_VERSION_ID >= 70100 && self::functionBodyIsGenerator($stmts)) {
                    $flags |= 0x800000;
                }
                 */
                return self::newAstDecl(
                    ast\AST_METHOD,
                    $flags,
                    [
                        'params' => self::phpparserParamsToAstParams($n->params, $start_line),
                        'uses' => null,  // TODO: anonymous class?
                        'stmts' => $stmts,
                        'returnType' => self::phpparserTypeToAstNode($n->returnType, sl($n->returnType) ?: $start_line)
                    ],
                    $start_line,
                    self::extractPhpdocComment($n->getAttribute('comments')),
                    $n->name,
                    $n->getAttribute('endLine'),
                    self::nextDeclId()
                );
            },
            'PhpParser\Node\Stmt\Const_' => function(PhpParser\Node\Stmt\Const_ $n, int $start_line) : ast\Node {
                return self::phpparserConstToAstNode($n, $start_line);
            },
            'PhpParser\Node\Stmt\Continue_' => function(PhpParser\Node\Stmt\Continue_ $n, int $start_line) : ast\Node {
                return new ast\Node(ast\AST_CONTINUE, 0, ['depth' => isset($n->num) ? self::phpparserNodeToAstNode($n->num) : null], $start_line);
            },
            'PhpParser\Node\Stmt\Declare_' => function(PhpParser\Node\Stmt\Declare_ $n, int $start_line) : ast\Node {
                return self::astStmtDeclare(
                    self::phpparserDeclareListToAstDeclares($n->declares, $start_line, self::extractPhpdocComment($n->getAttribute('comments'))),
                    is_array($n->stmts) ? self::phpparserStmtlistToAstNode($n->stmts, $start_line) : null,
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\Do_' => function(PhpParser\Node\Stmt\Do_ $n, int $start_line) : ast\Node {
                return self::astNodeDoWhile(
                    self::phpparserNodeToAstNode($n->cond),
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line
                );
            },
            /**
             * @return ast\Node|ast\Node[]
             */
            'PhpParser\Node\Stmt\Echo_' => function(PhpParser\Node\Stmt\Echo_ $n, int $start_line) {
                $ast_echos = [];
                foreach ($n->exprs as $expr) {
                    $ast_echos[] = self::astStmtEcho(
                        self::phpparserNodeToAstNode($expr),
                        $start_line
                    );
                }
                return count($ast_echos) === 1 ? $ast_echos[0] : $ast_echos;
            },
            'PhpParser\Node\Stmt\Foreach_' => function(PhpParser\Node\Stmt\Foreach_ $n, int $start_line) : ast\Node {
                $value = self::phpparserNodeToAstNode($n->valueVar);
                if ($n->byRef) {
                    $value = new ast\Node(
                        ast\AST_REF,
                        0,
                        ['var' => $value],
                        $value->lineno ?? $start_line
                    );
                }
                return new ast\Node(
                    ast\AST_FOREACH,
                    0,
                    [
                        'expr' => self::phpparserNodeToAstNode($n->expr),
                        'value' => $value,
                        'key' => $n->keyVar !== null ? self::phpparserNodeToAstNode($n->keyVar) : null,
                        'stmts' => self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    ],
                    $start_line
                );
                //return self::phpparserStmtlistToAstNode($n->stmts, $start_line);
            },
            'PhpParser\Node\Stmt\Finally_' => function(PhpParser\Node\Stmt\Finally_ $n, int $start_line) : ast\Node {
                return self::phpparserStmtlistToAstNode($n->stmts, $start_line);
            },
            'PhpParser\Node\Stmt\Function_' => function(PhpParser\Node\Stmt\Function_ $n, int $start_line) : ast\Node {
                $end_line = $n->getAttribute('endLine') ?: $start_line;
                $return_type = $n->returnType;
                $return_type_line = sl($return_type) ?: $end_line;
                $ast_return_type = self::phpparserTypeToAstNode($return_type, $return_type_line);

                return self::astDeclFunction(
                    $n->byRef,
                    $n->name,
                    self::phpparserParamsToAstParams($n->params, $start_line),
                    null,  // uses
                    self::phpparserTypeToAstNode($return_type, $return_type_line),
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line,
                    $end_line,
                    self::extractPhpdocComment($n->getAttribute('comments'))
                );
            },
            /** @return ast\Node|ast\Node[] */
            'PhpParser\Node\Stmt\Global_' => function(PhpParser\Node\Stmt\Global_ $n, int $start_line) {
                $global_nodes = [];
                foreach ($n->vars as $var) {
                    $global_nodes[] = new ast\Node(ast\AST_GLOBAL, 0, ['var' => self::phpparserNodeToAstNode($var)], sl($var) ?: $start_line);
                }
                return \count($global_nodes) === 1 ? $global_nodes[0] : $global_nodes;
            },
            'PhpParser\Node\Stmt\Goto_' => function(PhpParser\Node\Stmt\Goto_ $n, int $start_line) : ast\Node {
                return new ast\Node(
                    \ast\AST_GOTO,
                    0,
                    ['label' => $n->name],
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\HaltCompiler' => function(PhpParser\Node\Stmt\HaltCompiler $n, int $start_line) : ast\Node {
                return new ast\Node(
                    \ast\AST_HALT_COMPILER,
                    0,
                    ['offset' => 'TODO compute halt compiler offset'],  // FIXME implement
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\If_' => function(PhpParser\Node\Stmt\If_ $n, int $start_line) : ast\Node {
                return self::phpparserIfStmtToAstIfStmt($n);
            },
            'PhpParser\Node\Stmt\InlineHTML' => function(PhpParser\Node\Stmt\InlineHTML $n, int $start_line) : ast\Node {
                return self::astStmtEcho($n->value, $start_line);
            },
            'PhpParser\Node\Stmt\Interface_' => function(PhpParser\Node\Stmt\Interface_ $n, int $start_line) : ast\Node {
                $end_line = $n->getAttribute('endLine') ?: $start_line;
                return self::astStmtClass(
                    ast\flags\CLASS_INTERFACE,
                    $n->name,
                    null,
                    // php-ast calls these 'implements', PHP-Parser calls these 'extends' on an interface
                    $n->extends,
                    is_array($n->stmts) ? self::phpparserStmtlistToAstNode($n->stmts, $start_line) : null,
                    $start_line,
                    $end_line,
                    self::extractPhpdocComment($n->getAttribute('comments'))
                );
            },
            'PhpParser\Node\Stmt\Label' => function(PhpParser\Node\Stmt\Label $n, int $start_line) : ast\Node {
                return new ast\Node(
                    \ast\AST_LABEL,
                    0,
                    ['name' => $n->name],
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\For_' => function(PhpParser\Node\Stmt\For_ $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_FOR,
                    0,
                    [
                        'init' => \count($n->init) > 0 ? self::phpparserExprListToExprList($n->init, $start_line) : null,
                        'cond' => \count($n->cond) > 0 ? self::phpparserExprListToExprList($n->cond, $start_line) : null,
                        'loop' => \count($n->loop) > 0 ? self::phpparserExprListToExprList($n->loop, $start_line) : null,
                        'stmts' => self::phpparserStmtlistToAstNode($n->stmts ?? [], $start_line),
                    ],
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\GroupUse' => function(PhpParser\Node\Stmt\GroupUse $n, int $start_line) : ast\Node {
                return self::astStmtGroupUse(
                    $n->type,
                    self::phpparserNameToString($n->prefix),
                    self::phpparserUseListToAstUseList($n->uses),
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\Namespace_' => function(PhpParser\Node\Stmt\Namespace_ $n, int $start_line) : array {
                $php_parser_stmts = $n->stmts;
                \assert(\is_array($php_parser_stmts));
                $name = $n->name !== null ? self::phpparserNameToString($n->name) : null;
                $stmts_of_namespace = is_array($php_parser_stmts) ? self::phpparserStmtlistToAstNode($php_parser_stmts, $start_line) : null;
                $end_line_of_wrapper = null;
                $end_line_of_contents = null;
                if ($stmts_of_namespace !== null) {
                    $end_line_of_wrapper = ($n->getAttribute('endLine') ?: $n->getAttribute('startLine') ?: null);
                    $within_namespace = $n->stmts ?? [];
                    if (count($within_namespace) > 0) {
                        foreach ($within_namespace as $s) {
                            $end_line_of_contents = $s->getAttribute('endLine') ?: $s->getAttribute('startLine');
                            if ($end_line_of_contents && $end_line_of_contents != $end_line_of_wrapper) {
                                break;
                            }
                        }
                    }
                }

                if ($name === null || $end_line_of_contents <= $end_line_of_wrapper) {
                    // `namespace {}` (explicit global namespace) can only be written one way.
                    // If a child nodes line number fall within the same range, assume it's a namespace {}
                    return [
                        new ast\Node(
                            ast\AST_NAMESPACE,
                            0,
                            [
                                'name' => $name,
                                'stmts' => $stmts_of_namespace,
                            ],
                            $start_line
                        )
                    ];
                }
                return array_merge([
                    new ast\Node(
                        ast\AST_NAMESPACE,
                        0,
                        [
                            'name' => $name,
                            'stmts' => null,
                        ],
                        $start_line
                    )
                ], $stmts_of_namespace->children ?? []);
            },
            'PhpParser\Node\Stmt\Nop' => function(PhpParser\Node\Stmt\Nop $n, int $start_line) : array {
                // `;;`
                return [];
            },
            'PhpParser\Node\Stmt\Property' => function(PhpParser\Node\Stmt\Property $n, int $start_line) : ast\Node {
                return self::phpparserPropertyToAstNode($n, $start_line);
            },
            'PhpParser\Node\Stmt\Return_' => function(PhpParser\Node\Stmt\Return_ $n, int $start_line) : ast\Node {
                return self::astStmtReturn($n->expr !== null ? self::phpparserNodeToAstNode($n->expr) : null, $start_line);
            },
            /** @return ast\Node|ast\Node[] */
            'PhpParser\Node\Stmt\Static_' => function(PhpParser\Node\Stmt\Static_ $n, int $start_line) {
                $static_nodes = [];
                foreach ($n->vars as $var) {
                    $static_nodes[] = new ast\Node(ast\AST_STATIC, 0, [
                        'var' => new ast\Node(ast\AST_VAR, 0, ['name' => $var->name], sl($var) ?: $start_line),
                        'default' => $var->default !== null ? self::phpparserNodeToAstNode($var->default) : null,
                    ], sl($var) ?: $start_line);
                }
                return \count($static_nodes) === 1 ? $static_nodes[0] : $static_nodes;
            },
            'PhpParser\Node\Stmt\Switch_' => function(PhpParser\Node\Stmt\Switch_ $n, int $start_line) : ast\Node {
                return self::phpparserSwitchListToAstSwitch($n);
            },
            'PhpParser\Node\Stmt\Throw_' => function(PhpParser\Node\Stmt\Throw_ $n, int $start_line) : ast\Node {
                return new ast\Node(
                    ast\AST_THROW,
                    0,
                    ['expr' => self::phpparserNodeToAstNode($n->expr)],
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\Trait_' => function(PhpParser\Node\Stmt\Trait_ $n, int $start_line) : ast\Node {
                $end_line = $n->getAttribute('endLine') ?: $start_line;
                return self::astStmtClass(
                    ast\flags\CLASS_TRAIT,
                    $n->name,
                    null,
                    null,
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line,
                    $end_line,
                    self::extractPhpdocComment($n->getAttribute('comments'))
                );
            },
            'PhpParser\Node\Stmt\TraitUse' => function(PhpParser\Node\Stmt\TraitUse $n, int $start_line) : ast\Node {
                if (\is_array($n->adaptations) && \count($n->adaptations) > 0) {
                    $adaptations_inner = array_map(function(PhpParser\Node\Stmt\TraitUseAdaptation $n) : ast\Node {
                        return self::phpparserNodeToAstNode($n);
                    }, $n->adaptations);
                    $adaptations = new ast\Node(ast\AST_TRAIT_ADAPTATIONS, 0, $adaptations_inner, $adaptations_inner[0]->lineno ?: $start_line);
                } else {
                    $adaptations = null;
                }
                return new ast\Node(
                    ast\AST_USE_TRAIT,
                    0,
                    [
                        'traits' => self::phpparserNameListToAstNameList($n->traits, $start_line),
                        'adaptations' => $adaptations,
                    ],
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\TraitUseAdaptation\Alias' => function(PhpParser\Node\Stmt\TraitUseAdaptation\Alias $n, int $start_line) : ast\Node {
                $old_class = $n->trait !== null ? self::phpparserNodeToAstNode($n->trait) : null;
                $flags = ($n->trait instanceof PhpParser\Node\Name\FullyQualified) ? ast\flags\NAME_FQ : ast\flags\NAME_NOT_FQ;
                // TODO: flags for visibility
                return new ast\Node(ast\AST_TRAIT_ALIAS, self::phpparserVisibilityToAstVisibility($n->newModifier ?? 0, false), [
                    'method' => new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                        'class' => $old_class,
                        'method' => $n->method,
                    ], $start_line),
                    'alias' => $n->newName,
                ], $start_line);
            },
            'PhpParser\Node\Stmt\TraitUseAdaptation\Precedence' => function(PhpParser\Node\Stmt\TraitUseAdaptation\Precedence $n, int $start_line) : ast\Node {
                $old_class = $n->trait !== null ? self::phpparserNameToString($n->trait) : null;
                $flags = ($n->trait instanceof PhpParser\Node\Name\FullyQualified) ? ast\flags\NAME_FQ : ast\flags\NAME_NOT_FQ;
                // TODO: flags for visibility
                return new ast\Node(ast\AST_TRAIT_PRECEDENCE, 0, [
                    'method' => new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                        'class' => new ast\Node(ast\AST_NAME, $flags, ['name' => $old_class], $start_line),
                        'method' => $n->method,
                    ], $start_line),
                    'insteadof' => self::phpparserNameListToAstNameList($n->insteadof, $start_line),
                ], $start_line);
            },
            'PhpParser\Node\Stmt\TryCatch' => function(PhpParser\Node\Stmt\TryCatch $n, int $start_line) : ast\Node {
                if (!is_array($n->catches)) {
                    throw new \Error(sprintf("Unsupported type %s\n%s", get_class($n), var_export($n->catches, true)));
                }
                return self::astNodeTry(
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line), // $n->try
                    self::phpparserCatchlistToAstCatchlist($n->catches, $start_line),
                    isset($n->finally) ? self::phpparserStmtlistToAstNode($n->finally->stmts, sl($n->finally)) : null,
                    $start_line
                );
            },
            /** @return ast\Node|ast\Node[] */
            'PhpParser\Node\Stmt\Unset_' => function(PhpParser\Node\Stmt\Unset_ $n, int $start_line) {
                $stmts = [];
                foreach ($n->vars as $var) {
                    $stmts[] = new ast\Node(ast\AST_UNSET, 0, ['var' => self::phpparserNodeToAstNode($var)], sl($var) ?: $start_line);
                }
                return \count($stmts) === 1 ? $stmts[0] : $stmts;
            },
            'PhpParser\Node\Stmt\Use_' => function(PhpParser\Node\Stmt\Use_ $n, int $start_line) : ast\Node {
                return self::astStmtUse(
                    $n->type,
                    self::phpparserUseListToAstUseList($n->uses),
                    $start_line
                );
            },
            'PhpParser\Node\Stmt\While_' => function(PhpParser\Node\Stmt\While_ $n, int $start_line) : ast\Node {
                return self::astNodeWhile(
                    self::phpparserNodeToAstNode($n->cond),
                    self::phpparserStmtlistToAstNode($n->stmts, $start_line),
                    $start_line
                );
            },
        ];

        foreach ($closures as $key => $value) {
            assert(class_exists($key), "Class $key should exist");
        }
        return $closures;
    }

    private static function astNodeTry(
        $try_node,
        $catches_node,
        $finally_node,
        int $start_line
    ) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_TRY;
        $node->flags = 0;
        $node->lineno = $start_line;
        $children = [
            'try' => $try_node,
        ];
        if ($catches_node !== null) {
            $children['catches'] = $catches_node;
        }
        $children['finally'] = $finally_node;
        $node->children = $children;
        return $node;
    }

    // FIXME types
    private static function astStmtCatch($types, string $var, $stmts, int $lineno) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_CATCH;
        $node->lineno = $lineno;
        $node->flags = 0;
        $node->children = [
            'class' => $types,
            'var' => new ast\Node(ast\AST_VAR, 0, ['name' => $var], end($types->children)->lineno),  // FIXME AST_VAR
            'stmts' => $stmts,
        ];
        return $node;
    }

    private static function phpparserCatchlistToAstCatchlist(array $catches, int $lineno) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_CATCH_LIST;
        $node->flags = 0;
        $children = [];
        foreach ($catches as $parser_catch) {
            $children[] = self::phpparserNodeToAstNode($parser_catch);
        }
        $node->lineno = $children[0]->lineno ?? $lineno;
        $node->children = $children;
        return $node;
    }

    private static function phpparserNameListToAstNameList(array $types, int $line) : ast\Node {
        $ast_types = [];
        foreach ($types as $type) {
            $ast_types[] = self::phpparserNodeToAstNode($type);
        }
        return new ast\Node(ast\AST_NAME_LIST, 0, $ast_types, $line);
    }

    private static function astNodeWhile($cond, $stmts, int $start_line) : ast\Node {
        return new ast\Node(
            ast\AST_WHILE,
            0,
            [
                'cond' => $cond,
                'stmts' => $stmts,
            ],
            $start_line
        );
    }

    private static function astNodeDoWhile($cond, $stmts, int $start_line) : ast\Node {
        return new ast\Node(
            ast\AST_DO_WHILE,
            0,
            [
                'stmts' => $stmts,
                'cond' => $cond,
            ],
            $start_line
        );
    }

    private static function astNodeAssign($var, $expr, int $line, bool $ref) : ?ast\Node {
        if ($expr === null) {
            if (self::$should_add_placeholders) {
                $expr = '__INCOMPLETE_EXPR__';
            } else {
                return null;
            }
        }
        return new ast\Node($ref ? ast\AST_ASSIGN_REF : ast\AST_ASSIGN, 0, [
            'var'  => $var,
            'expr' => $expr,
        ], $line);
    }

    private static function astNodeUnaryOp(int $flags, $expr, int $line) : ast\Node {
        return new ast\Node(ast\AST_UNARY_OP, $flags, ['expr' => $expr], $line);
    }

    private static function astNodeCast(int $flags, PhpParser\Node\Expr\Cast $n, int $line) : ast\Node {
        return new ast\Node(ast\AST_CAST, $flags, ['expr' => self::phpparserNodeToAstNode($n->expr)], sl($n) ?: $line);
    }

    private static function astNodeEval($expr, int $line) : ast\Node {
        return new ast\Node(ast\AST_INCLUDE_OR_EVAL, ast\flags\EXEC_EVAL, ['expr' => $expr], $line);
    }

    private static function phpparserIncludeFlagsToAstIncludeFlags(int $type) : int {
        switch($type) {
        case PhpParser\Node\Expr\Include_::TYPE_INCLUDE:
            return ast\flags\EXEC_INCLUDE;
        case PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE:
            return ast\flags\EXEC_INCLUDE_ONCE;
        case PhpParser\Node\Expr\Include_::TYPE_REQUIRE:
            return ast\flags\EXEC_REQUIRE;
        case PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE:
            return ast\flags\EXEC_REQUIRE_ONCE;
        default:
            throw new \Error("Unrecognized PhpParser include/require type $type");
        }
    }
    private static function astNodeInclude($expr, int $line, int $type) : ast\Node {
        $flags = self::phpparserIncludeFlagsToAstIncludeFlags($type);
        return new ast\Node(ast\AST_INCLUDE_OR_EVAL, $flags, ['expr' => $expr], $line);
    }

    /**
     * @param PhpParser\Node\Name|PhpParser\Node\Name\FullyQualified|string|null $type
     * @return ast\Node|null
     */
    private static function phpparserTypeToAstNode($type, int $line) {
        if (is_null($type)) {
            return $type;
        }
        $original_type = $type;
        if ($type instanceof PhpParser\Node\Name) {
            $type = self::phpparserNameToString($type);
        }
        if (\is_string($type)) {
            switch(strtolower($type)) {
            case 'null':
                $flags = ast\flags\TYPE_NULL; break;
            case 'bool':
                $flags = ast\flags\TYPE_BOOL; break;
            case 'int':
                $flags = ast\flags\TYPE_LONG; break;
            case 'float':
                $flags = ast\flags\TYPE_DOUBLE; break;
            case 'string':
                $flags = ast\flags\TYPE_STRING; break;
            case 'array':
                $flags = ast\flags\TYPE_ARRAY; break;
            case 'object':
            case '\\object':
                if (self::$ast_version >= 45) {
                    $flags = ast\flags\TYPE_OBJECT; break;
                } else {
                    return new ast\Node(
                        ast\AST_NAME,
                        ast\flags\NAME_NOT_FQ,
                        [
                            'name' => 'object',
                        ],
                        $line
                    );
                }
            case 'callable':
                $flags = ast\flags\TYPE_CALLABLE; break;
            case 'void':
                $flags = ast\flags\TYPE_VOID; break;
            case 'iterable':
                $flags = ast\flags\TYPE_ITERABLE; break;
            default:
                $name_flags = \ast\flags\NAME_NOT_FQ;
                if (is_object($original_type)) {
                    if ($original_type instanceof PhpParser\Node\Name\FullyQualified) {
                        $name_flags = ast\flags\NAME_FQ;
                    } else if ($original_type instanceof PhpParser\Node\Name\Relative) {
                        $name_flags = ast\flags\NAME_RELATIVE;
                    }
                }

                return new ast\Node(
                    ast\AST_NAME,
                    $name_flags,
                    [
                        'name' => $type,
                    ],
                    $line
                );
            }
            $node = new ast\Node();
            $node->kind = ast\AST_TYPE;
            $node->flags = $flags;
            $node->lineno = $line;
            $node->children = [];
            return $node;
        }
        // FIXME: Investigate instances of the other cases?
        return self::phpparserNodeToAstNode($type);
    }

    /**
     * @param bool $by_ref
     * @param ?ast\Node $type
     */
    private static function astNodeParam(bool $by_ref, bool $variadic, $type, $name, $default, int $line) : ast\Node {
        $node = new ast\Node;
        $node->kind = ast\AST_PARAM;
        $node->flags = ($by_ref ? ast\flags\PARAM_REF : 0) | ($variadic ? ast\flags\PARAM_VARIADIC : 0);
        $node->lineno = $line;
        $node->children = [
            'type' => $type,
            'name' => $name,
            'default' => $default,
        ];

        return $node;
    }

    private static function astNodeNullableType(ast\Node $type, int $line) {
        $node = new ast\Node;
        $node->kind = ast\AST_NULLABLE_TYPE;
        // FIXME: Why is this a special case in php-ast? (e.g. nullable int has no flags on the nullable node)
        $node->flags = 0;
        $node->lineno = $line;
        $node->children = ['type' => $type];
        return $node;
    }

    private static function astNodeVariable($expr, int $line) : ?ast\Node {
        // TODO: 2 different ways to handle an Error. 1. Add a placeholder. 2. remove all of the statements in that tree.
        if ($expr instanceof PhpParser\Node) {
            $expr = self::phpparserNodeToAstNode($expr);
            if ($expr === null) {
                if (self::$should_add_placeholders) {
                    $expr = '__INCOMPLETE_VARIABLE__';
                } else {
                    return null;
                }
            }
        }
        $node = new ast\Node;
        $node->kind = ast\AST_VAR;
        $node->flags = 0;
        $node->lineno = $line;
        $node->children = ['name' => $expr];
        return $node;
    }

    private static function astMagicConst(int $flags, int $line) {
        return new ast\Node(ast\AST_MAGIC_CONST, $flags, [], $line);
    }

    private static function phpparserParamsToAstParams(array $parser_params, int $line) : ast\Node {
        $new_params = [];
        foreach ($parser_params as $parser_node) {
            $new_params[] = self::phpparserNodeToAstNode($parser_node);
        }
        $new_params_node = new ast\Node();
        $new_params_node->kind = ast\AST_PARAM_LIST;
        $new_params_node->flags = 0;
        $new_params_node->children = $new_params;
        $new_params_node->lineno = $line;
        return $new_params_node;
    }

    /**
     * @suppress PhanTypeMismatchProperty - Deliberately wrong type of kind
     */
    private static function astStub($parser_node) : ast\Node{
        // Debugging code.
        if (getenv('AST_THROW_INVALID')) {
            throw new \Error("TODO:" . get_class($parser_node));
        }

        $node = new ast\Node();
        $node->kind = "TODO:" . get_class($parser_node);
        $node->flags = 0;
        $node->lineno = $parser_node->getAttribute('startLine');
        $node->children = null;
        return $node;
    }

    /**
     * @param PhpParser\Node\Expr\ClosureUse[] $uses
     * @param int $line
     * @return ?ast\Node
     */
    private static function phpparserClosureUsesToAstClosureUses(
        array $uses,
        int $line
    ) {
        if (count($uses) === 0) {
            return null;
        }
        $ast_uses = [];
        foreach ($uses as $use) {
            $ast_uses[] = new ast\Node(ast\AST_CLOSURE_VAR, $use->byRef ? 1 : 0, ['name' => $use->var], $use->getAttribute('startLine'));
        }
        return new ast\Node(ast\AST_CLOSURE_USES, 0, $ast_uses, $ast_uses[0]->lineno ?? $line);

    }

    private static function astDeclClosure(
        bool $by_ref,
        bool $static,
        ast\Node $params,
        $uses,
        $stmts,
        $return_type,
        int $start_line,
        int $end_line,
        ?string $doc_comment
    ) : ast\Node {
        $flags = 0;
        if ($by_ref) {
            $flags |= ast\flags\RETURNS_REF;
        }
        if ($static) {
            $flags |= ast\flags\MODIFIER_STATIC;
        }
        if (PHP_VERSION_ID >= 70100 && self::functionBodyIsGenerator($stmts)) {
            $flags |= 0x800000;
        }
        return self::newAstDecl(
            ast\AST_CLOSURE,
            $flags,
            [
                'params' => $params,
                'uses' => $uses,
                'stmts' => $stmts,
                'returnType' => $return_type,
            ],
            $start_line,
            $doc_comment,
            '{closure}',
            $end_line,
            self::nextDeclId()
        );
    }

    // If a node isn't in this map, then recurse.
    // If any of the child nodes has a true node (yield or yield from), then the function body is a generator.
    const _NODE_IS_YIELD_MAP = [
        // These begin a brand new context
        ast\AST_CLASS => false,  // TODO: handle the case for `new class ($param = yield) { ... anonymous class body ...}`
        ast\AST_FUNC_DECL => false,
        ast\AST_METHOD => false,
        ast\AST_CLOSURE => false,

        ast\AST_YIELD => true,
        ast\AST_YIELD_FROM => true,
    ];

    public static function functionBodyIsGenerator(?ast\Node $stmts) : bool {
        if (!$stmts) {
            return false;
        }
        $kind = $stmts->kind;
        $result = self::_NODE_IS_YIELD_MAP[$kind] ?? null;
        if ($result !== null) {
            return $result;
        }
        foreach ($stmts->children as $v) {
            if ($v instanceof ast\Node) {
                if (self::functionBodyIsGenerator($v)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function astDeclFunction(
        bool $by_ref,
        string $name,
        ast\Node $params,
        ?array $uses,
        $return_type,
        $stmts,
        int $line,
        int $end_line,
        ?string $doc_comment
    ) : ast\Node {
        $flags = 0;
        if ($by_ref) {
            $flags |= \ast\flags\RETURNS_REF;
        }
        /*
        if (PHP_VERSION_ID >= 70100 && self::functionBodyIsGenerator($stmts)) {
            $flags |= 0x800000;
        }
         */
        return self::newAstDecl(
            ast\AST_FUNC_DECL,
            $flags,
            [
                'params' => $params,
                'uses' => $uses,
                'stmts' => $stmts,
                'returnType' => $return_type,
            ],
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    private static function phpparserClassFlagsToAstClassFlags(int $flags) {
        $ast_flags = 0;
        if ($flags & PhpParser\Node\Stmt\Class_::MODIFIER_ABSTRACT) {
            $ast_flags |= ast\flags\CLASS_ABSTRACT;
        }
        if ($flags & PhpParser\Node\Stmt\Class_::MODIFIER_FINAL) {
            $ast_flags |= ast\flags\CLASS_FINAL;
        }
        return $ast_flags;
    }

    /**
     * @param int $flags
     * @param ?string $name
     * @param ?ast\Node $extends TODO
     * @param PHPParser\Node[]|null $implements
     * @param ?ast\Node $stmts
     * @param int $line
     * @param int $end_line
     */
    private static function astStmtClass(
        int $flags,
        ?string $name,
        ?ast\Node $extends,
        ?array $implements,
        ?ast\Node $stmts,
        int $line,
        int $end_line,
        ?string $doc_comment
    ) : ast\Node {
        if ($name === null) {
            $flags |= ast\flags\CLASS_ANONYMOUS;
        }

        if ($implements !== null && $implements !== []) {
            $ast_implements_inner = [];
            foreach ($implements as $implement) {
                $ast_implements_inner[] = self::phpparserNodeToAstNode($implement);
            }
            $ast_implements = new ast\Node(ast\AST_NAME_LIST, 0, $ast_implements_inner, $ast_implements_inner[0]->lineno);
        } else {
            $ast_implements = null;
        }

        return self::newAstDecl(
            ast\AST_CLASS,
            $flags,
            [
                'extends'    => $extends,
                'implements' => $ast_implements,
                'stmts'      => $stmts,
            ],
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    private static function phpparserArgListToAstArgList(array $args, int $line) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_ARG_LIST;
        $node->flags = 0;
        $ast_args = [];
        foreach ($args as $arg) {
            $ast_args[] = self::phpparserNodeToAstNode($arg);
        }
        $node->lineno = $ast_args[0]->lineno ?? $line;
        $node->children = $ast_args;
        return $node;
    }

    private static function phpparserUseListToAstUseList(?array $uses) : array {
        $ast_uses = [];
        foreach ($uses as $use) {
            $ast_use = new ast\Node();
            $ast_use->kind = ast\AST_USE_ELEM;
            $ast_use->flags = self::phpparserUseTypeToAstFlags($use->type);  // FIXME
            $ast_use->lineno = $use->getAttribute('startLine');
            // ast doesn't fill in an alias if it's identical to the real name,
            // but phpparser does?
            $name = implode('\\', $use->name->parts);
            $alias = $use->alias;
            $ast_use->children = [
                'name' => $name,
                'alias' => $alias !== end($use->name->parts) ? $alias : null,
            ];
            $ast_uses[] = $ast_use;
        }
        return $ast_uses;
    }

    /**
     * @param int $type
     */
    private static function phpparserUseTypeToAstFlags($type) : int {
        switch($type) {
        case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
            return ast\flags\USE_NORMAL;
        case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
            return ast\flags\USE_FUNCTION;
        case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
            return ast\flags\USE_CONST;
        case PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN:
        default:
            return 0;
        }
    }

    private static function astStmtUse($type, array $uses, int $line) : ast\Node{
        $node = new ast\Node();
        $node->kind = ast\AST_USE;
        $node->flags = self::phpparserUseTypeToAstFlags($type);
        $node->lineno = $line;
        $node->children = $uses;
        return $node;
    }

    private static function astStmtGroupUse($type, $prefix, array $uses, int $line) : ast\Node{
        $node = new ast\Node();
        $node->kind = ast\AST_GROUP_USE;
        $node->flags = self::phpparserUseTypeToAstFlags($type);
        $node->lineno = $line;
        $node->children = [
            'prefix' => $prefix,
            'uses' => self::astStmtUse(0, $uses, $line),
        ];
        return $node;
    }

    private static function astStmtEcho($expr, int $line) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_ECHO;
        $node->flags = 0;
        $node->lineno = $line;
        $node->children = ['expr' => $expr];
        return $node;
    }

    private static function astStmtReturn($expr, int $line) : ast\Node {
        $node = new ast\Node();
        $node->kind = ast\AST_RETURN;
        $node->flags = 0;
        $node->lineno = $line;
        $node->children = ['expr' => $expr];
        return $node;
    }

    private static function astIfElem($cond, $stmts, int $line) : ast\Node {
        return new ast\Node(ast\AST_IF_ELEM, 0, ['cond' => $cond, 'stmts' => $stmts], $line);
    }

    private static function phpparserSwitchListToAstSwitch(PhpParser\Node\Stmt\Switch_ $node) {
        $stmts = [];
        $node_line = sl($node) ?? 0;
        foreach ($node->cases as $case) {
            $case_line = sl($case);
            $stmts[] = new ast\Node(
                ast\AST_SWITCH_CASE,
                0,
                [
                    'cond' => $case->cond !== null ? self::phpparserNodeToAstNode($case->cond) : null,
                    'stmts' => self::phpparserStmtlistToAstNode($case->stmts, $case_line),
                ],
                $case_line ?? $node_line
            );
        }
        return new ast\Node(ast\AST_SWITCH, 0, [
            'cond' => self::phpparserNodeToAstNode($node->cond),
            'stmts' => new ast\Node(ast\AST_SWITCH_LIST, 0, $stmts, $stmts[0]->lineno ?? $node_line),
        ], $node_line);
    }

    private static function phpparserIfStmtToAstIfStmt(PhpParser\Node $node) : ast\Node {
        assert($node instanceof PhpParser\Node\Stmt\If_);
        $start_line = $node->getAttribute('startLine');
        $cond_line = sl($node->cond) ?: $start_line;
        $if_elem = self::astIfElem(
            self::phpparserNodeToAstNode($node->cond),
            self::phpparserStmtlistToAstNode($node->stmts, $cond_line),
            $start_line
        );
        $if_elems = [$if_elem];
        foreach ($node->elseifs as $else_if) {
            $if_elem_line = $else_if->getAttribute('startLine');
            $if_elem = self::astIfElem(
                self::phpparserNodeToAstNode($else_if->cond),
                self::phpparserStmtlistToAstNode($else_if->stmts, $if_elem_line),
                $if_elem_line
            );
            $if_elems[] = $if_elem;
        }
        $parser_else_node = $node->else;
        if ($parser_else_node) {
            $parser_else_line = $parser_else_node->getAttribute('startLine');
            $if_elems[] = self::astIfElem(
                null,
                self::phpparserStmtlistToAstNode($parser_else_node->stmts, $parser_else_line),
                $parser_else_line
            );
        }
        return new ast\Node(ast\AST_IF, 0, $if_elems, $start_line);

    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    private static function astNodeAssignop(int $flags, PhpParser\Node $node, int $start_line) {
        return new ast\Node(
            ast\AST_ASSIGN_OP,
            $flags,
            [
                'var' => self::phpparserNodeToAstNode($node->var),
                'expr' => self::phpparserNodeToAstNode($node->expr),
            ],
            $start_line
        );
    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    private static function astNodeBinaryop(int $flags, PhpParser\Node $n, int $start_line) {
        return new ast\Node(
            ast\AST_BINARY_OP,
            $flags,
            self::phpparserNodesToLeftRightChildren($n->left, $n->right),
            $start_line
        );
    }

    private static function phpparserNodesToLeftRightChildren($left, $right) : array {
        return [
            'left' => self::phpparserNodeToAstNode($left),
            'right' => self::phpparserNodeToAstNode($right),
        ];
    }

    private static function phpparserPropelemToAstPropelem(PhpParser\Node\Stmt\PropertyProperty $n, ?string $doc_comment) : ast\Node{
        $children = [
            'name' => $n->name,
            'default' => $n->default ? self::phpparserNodeToAstNode($n->default) : null,
        ];

        $start_line = $n->getAttribute('startLine');

        return self::newAstNode(ast\AST_PROP_ELEM, 0, $children, $start_line, self::extractPhpdocComment($n->getAttribute('comments') ?? $doc_comment));
    }

    private static function phpparserConstelemToAstConstelem(PhpParser\Node\Const_ $n, ?string $doc_comment) : ast\Node{
        $children = [
            'name' => $n->name,
            'value' => self::phpparserNodeToAstNode($n->value),
        ];

        $start_line = $n->getAttribute('startLine');

        return self::newAstNode(ast\AST_CONST_ELEM, 0, $children, $start_line, self::extractPhpdocComment($n->getAttribute('comments') ?? $doc_comment));
    }

    private static function phpparserVisibilityToAstVisibility(int $visibility, bool $automatically_add_public = true) : int {
        $ast_visibility = 0;
        if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_PUBLIC) {
            $ast_visibility |= ast\flags\MODIFIER_PUBLIC;
        } else if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_PROTECTED) {
            $ast_visibility |= ast\flags\MODIFIER_PROTECTED;
        } else if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_PRIVATE) {
            $ast_visibility |= ast\flags\MODIFIER_PRIVATE;
        } else {
            if ($automatically_add_public) {
                $ast_visibility |= ast\flags\MODIFIER_PUBLIC;
            }
        }
        if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_STATIC) {
            $ast_visibility |= ast\flags\MODIFIER_STATIC;
        }
        if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_ABSTRACT) {
            $ast_visibility |= ast\flags\MODIFIER_ABSTRACT;
        }
        if ($visibility & \PHPParser\Node\Stmt\Class_::MODIFIER_FINAL) {
            $ast_visibility |= ast\flags\MODIFIER_FINAL;
        }
        return $ast_visibility;
    }

    private static function phpparserPropertyToAstNode(PHPParser\Node\Stmt\Property $n, int $start_line) : ast\Node {

        $prop_elems = [];
        $doc_comment = self::extractPhpdocComment($n->getAttribute('comments'));
        foreach ($n->props as $i => $prop) {
            $prop_elems[] = self::phpparserPropelemToAstPropelem($prop, $i === 0 ? $doc_comment : null);
        }
        $flags = self::phpparserVisibilityToAstVisibility($n->flags, false);
        if ($flags === 0) {
            $flags = ast\flags\MODIFIER_PUBLIC;
        }

        return new ast\Node(ast\AST_PROP_DECL, $flags, $prop_elems, $prop_elems[0]->lineno ?: $start_line);
    }

    private static function phpparserClassConstToAstNode(PhpParser\Node\Stmt\ClassConst $n, int $start_line) : ast\Node {
        $const_elems = [];
        $doc_comment = self::extractPhpdocComment($n->getAttribute('comments'));
        foreach ($n->consts as $i => $prop) {
            $const_elems[] = self::phpparserConstelemToAstConstelem($prop, $i === 0 ? $doc_comment : null);
        }
        $flags = self::phpparserVisibilityToAstVisibility($n->flags);

        return new ast\Node(ast\AST_CLASS_CONST_DECL, $flags, $const_elems, $const_elems[0]->lineno ?: $start_line);
    }

    private static function phpparserConstToAstNode(PhpParser\Node\Stmt\Const_ $n, int $start_line) : ast\Node {
        $const_elems = [];
        $doc_comment = self::extractPhpdocComment($n->getAttribute('comments'));
        foreach ($n->consts as $i => $prop) {
            $const_elems[] = self::phpparserConstelemToAstConstelem($prop, $i === 0 ? $doc_comment : null);
        }

        return new ast\Node(ast\AST_CONST_DECL, 0, $const_elems, $const_elems[0]->lineno ?: $start_line);
    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    private static function phpparserDeclareListToAstDeclares(array $declares, int $start_line, string $first_doc_comment = null) : ast\Node {
        $ast_declare_elements = [];
        foreach ($declares as $declare) {
            $children = [
                'name' => $declare->key,
                'value' => self::phpparserNodeToAstNode($declare->value),
            ];
            $doc_comment = self::extractPhpdocComment($declare->getAttribute('comments')) ?? $first_doc_comment;
            $first_doc_comment = null;
            if (self::$ast_version >= 50) {
                $children['docComment'] = $doc_comment;
            }
            $node = new ast\Node(ast\AST_CONST_ELEM, 0, $children, $declare->getAttribute('startLine'));
            if (self::$ast_version < 50 && is_string($doc_comment)) {
                $node->docComment = $doc_comment;
            }
            $ast_declare_elements[] = $node;
        }
        return new ast\Node(ast\AST_CONST_DECL, 0, $ast_declare_elements, $start_line);

    }

    private static function astStmtDeclare(ast\Node $declares, ?ast\Node $stmts, int $start_line) : ast\Node{
        $children = [
            'declares' => $declares,
            'stmts' => $stmts,
        ];
        return new ast\Node(ast\AST_DECLARE, 0, $children, $start_line);
    }

    private static function astNodeCall($expr, $args, int $start_line) : ast\Node{
        if (\is_string($expr)) {
            if (substr($expr, 0, 1) === '\\') {
                $expr = substr($expr, 1);
            }
            $expr = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $expr], $start_line);
        }
        return new ast\Node(ast\AST_CALL, 0, ['expr' => $expr, 'args' => $args], $start_line);
    }

    private static function astNodeMethodCall($expr, $method, ast\Node $args, int $start_line) : ast\Node {
        return new ast\Node(ast\AST_METHOD_CALL, 0, ['expr' => $expr, 'method' => $method, 'args' => $args], $start_line);
    }

    private static function astNodeStaticCall($class, $method, ast\Node $args, int $start_line) : ast\Node {
        // TODO: is this applicable?
        if (\is_string($class)) {
            if (substr($class, 0, 1) === '\\') {
                $expr = substr($class, 1);
            }
            $class = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $class], $start_line);
        }
        return new ast\Node(ast\AST_STATIC_CALL, 0, ['class' => $class, 'method' => $method, 'args' => $args], $start_line);
    }

    private static function extractPhpdocComment($comments) : ?string {
        if (\is_string($comments)) {
            return $comments;
        }
        if ($comments === null) {
            return null;
        }
        assert(\is_array($comments));
        if (\count($comments) === 0) {
            return null;
        }
        for ($i = \count($comments) - 1; $i >= 0; $i--) {
            if ($comments[$i] instanceof PhpParser\Comment\Doc) {
                return $comments[$i]->getText();
            } else {
                // e.g. PhpParser\Comment; for a line comment
            }
        }
        return null;
        // return var_export($comments, true);
    }

    private static function phpparserListToAstList(PhpParser\Node\Expr\List_ $n, int $start_line) : ast\Node {
        $ast_items = [];
        foreach ($n->items as $item) {
            if ($item === null) {
                $ast_items[] = null;
            } else {
                $flags = $item->byRef ? \ast\flags\PARAM_REF : 0;
                $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, $flags, [
                    'value' => self::phpparserNodeToAstNode($item->value),
                    'key' => $item->key !== null ? self::phpparserNodeToAstNode($item->key) : null,
                ], $item->getAttribute('startLine'));
            }
        }

        // convert list($x,) to list($x), list(,) to list(), etc.
        if (\count($ast_items) > 0 && end($ast_items) === null) {
            array_pop($ast_items);
        }
        return new ast\Node(ast\AST_ARRAY, ast\flags\ARRAY_SYNTAX_LIST, $ast_items, $start_line);
    }

    private static function phpparserArrayToAstArray(PhpParser\Node\Expr\Array_ $n, int $start_line) : ast\Node {
        $ast_items = [];
        foreach ($n->items as $item) {
            if ($item === null) {
                $ast_items[] = null;
            } else {
                $flags = $item->byRef ? \ast\flags\PARAM_REF : 0;
                $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, $flags, [
                    'value' => self::phpparserNodeToAstNode($item->value),
                    'key' => $item->key !== null ? self::phpparserNodeToAstNode($item->key) : null,
                ], $item->getAttribute('startLine'));
            }
        }
        $flags = $n->getAttribute('kind') === PhpParser\Node\Expr\Array_::KIND_LONG ? ast\flags\ARRAY_SYNTAX_LONG : ast\flags\ARRAY_SYNTAX_SHORT;
        return new ast\Node(ast\AST_ARRAY, $flags, $ast_items, $start_line);
    }

    private static function phpparserPropertyfetchToAstProp(PhpParser\Node\Expr\PropertyFetch $n, int $start_line) : ?ast\Node {
        $name = $n->name;
        if (is_object($name)) {
            $name = self::phpparserNodeToAstNode($name);
        }
        if ($name === null) {
            if (self::$should_add_placeholders) {
                $name = '__INCOMPLETE_PROPERTY__';
            } else {
                return null;
            }
        }
        return new ast\Node(ast\AST_PROP, 0, [
            'expr'  => self::phpparserNodeToAstNode($n->var),
            'prop'  => $name,  // \ast\Node|string
        ], $start_line);
    }

    private static function phpparserClassconstfetchToAstClassconstfetch(PhpParser\Node\Expr\ClassConstFetch $n, int $start_line) : ?ast\Node {
        $name = $n->name;
        if (is_object($name)) {
            $name = self::phpparserNodeToAstNode($name);
        }
        if ($name === null) {
            if (self::$should_add_placeholders) {
                $name = '__INCOMPLETE_CLASS_CONST__';
            } else {
                return null;
            }
        }
        return new ast\Node(ast\AST_CLASS_CONST, 0, [
            'class' => self::phpparserNodeToAstNode($n->class),
            'const' => $name,
        ], $start_line);
    }

    private static function phpparserNameToString(PhpParser\Node\Name $name) : string {
        return implode('\\', $name->parts);
    }

    const _NODES_WITH_NULL_DOC_COMMENT = [
        ast\AST_CONST_ELEM => true,
        ast\AST_PROP_ELEM => true,
    ];

    /**
     * @suppress PhanUndeclaredProperty - docComment really exists.
     * NOTE: this may be removed in the future.
     *
     * Phan was used while developing this. The asserts can be cleaned up in the future.
     *
     * NOTE: in AST version <= 40, may creates docComment as a property, but in version >= 45, adds it to $children
     *
     * @return ast\Node
     */
    private static function newAstNode(int $kind, int $flags, array $children, int $lineno, string $doc_comment = null) : ast\Node {
        if (self::$ast_version >= 45) {
            if (is_string($doc_comment) || array_key_exists($kind, self::_NODES_WITH_NULL_DOC_COMMENT)) {
                $children['docComment'] = $doc_comment;
            }
            return new ast\Node($kind, $flags, $children, $lineno);
        }
        $node = new ast\Node($kind, $flags, $children, $lineno);
        if (is_string($doc_comment)) {
            $node->docComment = $doc_comment;
        }
        return $node;
    }

    /**
     * NOTE: this may be removed in the future.
     *
     * Phan was used while developing this. The asserts can be cleaned up in the future.
     *
     * NOTE: in AST version >= 45, this returns Node, but in version <=40, this returns Decl
     *
     * @return ast\Node|ast\Node\Decl
     * @suppress PhanUndeclaredProperty
     */
    private static function newAstDecl(int $kind, int $flags, array $children, int $lineno, string $doc_comment = null, string $name = null, int $end_lineno = 0, int $decl_id = -1) : ast\Node {
        if (self::$ast_version >= 45) {
            $children45 = [];
            $children45['name'] = $name;
            $children45['docComment'] = $doc_comment;
            $children45 += $children;
            if ($decl_id >= 0 && self::$ast_version >= 50) {
                $children45['__declId'] = $decl_id;
            }
            $node = new ast\Node($kind, $flags, $children45, $lineno);
            if (is_int($end_lineno)) {
                $node->endLineno = $end_lineno;
            }
            return $node;
        }
        $decl = new ast\Node\Decl($kind, $flags, $children, $lineno);
        if (\is_string($doc_comment)) {
            $decl->docComment = $doc_comment;
        }
        $decl->name = $name;
        $decl->endLineno = $end_lineno;
        return $decl;
    }

    private static function nextDeclId() : int {
        return self::$decl_id++;
    }

}

function sl($node) : ?int {
    if ($node instanceof PhpParser\Node) {
        return $node->getAttribute('startLine');
    }
    return null;
}

function el($node) : ?int {
    if ($node instanceof PhpParser\Node) {
        return $node->getAttribute('endLine');
    }
    return null;
}
