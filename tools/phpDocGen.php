#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

const PREFIX_FN_BOTTOMLINE = 'bottomline_';

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Description;
use phpDocumentor\Reflection\DocBlock\Serializer;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlock\Tags\Method;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Since;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Mixed_;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Void_;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Render a given DocBlock as a string.
 *
 * @param DocBlock $docBlock
 *
 * @return string
 */
function writeDocBlock(DocBlock $docBlock)
{
    return (new Serializer())->getDocComment($docBlock);
}

abstract class Parsers
{
    /** @var DocBlockFactory */
    public static $docBlockParser;

    /** @var Parser */
    public static $phpParser;

    /** @var Parsedown */
    public static $markdown;

    public static function setup()
    {
        if (!isset(self::$docBlockParser)) {
            self::$docBlockParser = DocBlockFactory::createInstance();
        }

        if (!isset(self::$phpParser)) {
            self::$phpParser = (new ParserFactory())->create(ParserFactory::PREFER_PHP5);
        }

        if (!isset(self::$markdown)) {
            self::$markdown = new Parsedown();
        }
    }
}

class DocumentationRegistry implements JsonSerializable
{
    /** @var array<string, int> */
    public $namespaceCount;

    /** @var array<int, FunctionDocumentation> */
    public $methods;

    public function __construct()
    {
        $this->namespaceCount = [];
        $this->methods = [];
    }

    /**
     * @param string $filePath
     *
     * @return bool
     */
    public function registerDocumentationFromFile($filePath)
    {
        $fileName = basename($filePath);
        $namespace = basename(dirname($filePath));

        // If the file starts with an uppercase letter, then it's a class so let's not count it
        if (preg_match('/[A-Z]/', substr($fileName, 0, 1)) === 1) {
            return false;
        }

        if (!isset($this->namespaceCount[$namespace])) {
            $this->namespaceCount[$namespace] = 1;
        } else {
            $this->namespaceCount[$namespace]++;
        }

        include $filePath;

        $this->parsePhpSource($filePath, $fileName, $namespace);

        return true;
    }

    public function jsonSerialize()
    {
        // TODO: Implement jsonSerialize() method.
    }

    /**
     * @param string $filePath
     * @param string $fileName
     * @param string $namespace
     */
    private function parsePhpSource($filePath, $fileName, $namespace)
    {
        $parsedPhp = Parsers::$phpParser->parse(file_get_contents($filePath));
        /** @var Stmt\Namespace_|Stmt\Return_ $rootElement */
        $rootElement = array_shift($parsedPhp);

        switch ($rootElement->getType()) {
            case 'Stmt_Namespace':
                $nodes = $rootElement->stmts;

                foreach ($nodes as $node) {
                    if ($node->getType() !== 'Stmt_Function') {
                        continue;
                    }

                    $fxnName = $node->name;
                    $comments = $node->getAttribute('comments');

                    /** @var Comment $docBlock */
                    $docBlock = array_pop($comments);

                    $this->registerBottomlineFunction($fxnName, $docBlock, $namespace);
                }

                break;

            case 'Stmt_Return':
                $fxnName = pathinfo($fileName, PATHINFO_FILENAME);
                $comments = $rootElement->getAttribute('comments');

                /** @var Comment $docBlock */
                $docBlock = array_pop($comments);

                $this->registerBottomlineFunction($fxnName, $docBlock, null, $rootElement->expr->value);

                break;

            default:
                break;
        }
    }

    /**
     * @param string      $functionName
     * @param Comment     $docBlock
     * @param string|null $namespace
     * @param string|null $fqfn
     */
    private function registerBottomlineFunction($functionName, Comment $docBlock, $namespace = null, $fqfn = null)
    {
        try {
            // If function name starts with an underscore, it's a helper function not part of the API
            if (substr($functionName, 0, 1) === '_') {
                return;
            }

            if ($namespace !== null) {
                $fullyQualifiedFunctionName = sprintf("%s\\%s", $namespace, $functionName);
            } elseif ($fqfn !== null) {
                $fullyQualifiedFunctionName = $fqfn;
            } else {
                $fullyQualifiedFunctionName = $functionName;
            }

            $docBlock = Parsers::$docBlockParser->create($docBlock->getText());
            $isInternal = count($docBlock->getTagsByName('internal')) > 0;

            if ($isInternal) {
                return;
            }

            $functionDefinition = new ReflectionFunction($fullyQualifiedFunctionName);
            $this->methods[] = new FunctionDocumentation($functionDefinition, $docBlock, $functionName);
        } catch (Exception $e) {
            printf("Exception message: %s\n", $e->getMessage());
            printf("  %s\n\n", $functionName);
        }
    }
}

class FunctionDocumentation implements JsonSerializable
{
    /** @var ReflectionFunction */
    private $reflectedFunction;

    /** @var DocBlock */
    private $docBlock;

    /** @var string */
    public $name;

    /** @var string */
    public $namespace;

    /** @var string */
    public $summary;

    /** @var string */
    public $description;

    /** @var array<int, ArgumentDocumentation> */
    public $arguments;

    /** @var array<string, string> */
    public $changelog;

    /** @var array<string, string> */
    public $exceptions;

    /** @var Type */
    public $returnType;

    /** @var string */
    public $returnDescription;

    public function __construct(ReflectionFunction $reflectedFunction, DocBlock $docBlock, $functionName)
    {
        $this->reflectedFunction = $reflectedFunction;
        $this->docBlock = $docBlock;

        $this->namespace = $reflectedFunction->getNamespaceName();
        $this->name = $functionName;
        $this->arguments = [];
        $this->changelog = [];
        $this->exceptions = [];

        $this->parse();
    }

    /**
     * @return Method
     */
    public function asMethodTag()
    {
        $description = $this->description;

        if (count($this->changelog) > 0) {
            $description .= '<h2>Changelog</h2>';
            $description .= '<ul>';

            foreach ($this->changelog as $version => $desc) {
                $body = Parsers::$markdown->text("`{$version}` - {$desc}");
                $description .= "<li>{$body}</li>";
            }

            $description .= '</ul>';
        }

        if (count($this->exceptions) > 0) {
            $description .= '<h2>Exceptions</h2>';
            $description .= '<ul>';

            foreach ($this->exceptions as $name => $desc) {
                $body = Parsers::$markdown->text("`{$name}` - {$desc}");
                $description .= "<li>{$body}</li>";
            }

            $description .= '</ul>';
        }

        if ($this->returnDescription) {
            $description .= '<h2>Returns</h2>';
            $description .= Parsers::$markdown->text($this->returnDescription);
        }

        $descriptionBody = trim(preg_replace('/\n/', ' ', $this->summary . '<br>' . $description));
        $descriptionBody = preg_replace('/<br>$/', '', $descriptionBody);
        $description = new Description($descriptionBody);

        return new Method(
            $this->name,
            __::map($this->arguments, function (ArgumentDocumentation $argument) {
                return $argument->getMethodArgumentDefinition();
            }),
            $this->returnType,
            true,
            $description
        );
    }

    public function jsonSerialize()
    {
        // TODO: Implement jsonSerialize() method.
    }

    private function parse()
    {
        /** @var Param[] $documentedArgs */
        $documentedArgs = $this->docBlock->getTagsByName('param');
        /** @var ReflectionParameter[] $actualArgs */
        $actualArgs = [];

        foreach ($this->reflectedFunction->getParameters() as $parameter) {
            $actualArgs[$parameter->getName()] = $parameter;
        }

        foreach ($documentedArgs as $documentedArg) {
            $varName = $documentedArg->getVariableName();

            if (!isset($actualArgs[$varName])) {
                if ($documentedArg->isVariadic()) {
                    $this->arguments[] = new ArgumentDocumentation($documentedArg, null);
                }

                continue;
            }

            $this->arguments[] = new ArgumentDocumentation($documentedArg, $actualArgs[$varName]);
        }

        $this->summary = Parsers::$markdown->text($this->docBlock->getSummary());
        $this->description = Parsers::$markdown->text($this->docBlock->getDescription()->render());

        $this->parseCodeBlocks();
        $this->parseChangelog();
        $this->parseExceptions();
        $this->parseReturnType();

        // Change documented names for function like max which are declared in files
        // with a function prefix name (to avoid clash with PHP generic function max).
        if (substr($this->name, 0, strlen(PREFIX_FN_BOTTOMLINE)) === PREFIX_FN_BOTTOMLINE) {
            $this->name = str_replace(PREFIX_FN_BOTTOMLINE, '', $this->name);
        }
    }

    private function parseCodeBlocks()
    {
        // Extract <pre> blocks and replace their new lines with `<br>` so they can be formatted nicely by IDEs
        $codeBlocks = [];
        preg_match_all("/(<pre>(?:\s|.)*?<\/pre>)/", $this->description, $codeBlocks);

        // This means there were a few code blocks
        if (count($codeBlocks) == 2) {
            foreach ($codeBlocks[1] as $codeBlock) {
                $this->description = str_replace($codeBlock, nl2br($codeBlock), $this->description);
            }
        }
    }

    private function parseChangelog()
    {
        $sinceChangeLog = $this->docBlock->getTagsByName('since');

        if (count($sinceChangeLog) === 0) {
            return;
        }

        /** @var Since $item */
        foreach ($sinceChangeLog as $item) {
            $this->changelog[$item->getVersion()] = $item->getDescription();
        }
    }

    private function parseExceptions()
    {
        $exceptions = $this->docBlock->getTagsByName('throws');

        if (count($exceptions) === 0) {
            return;
        }

        /** @var DocBlock\Tags\Throws $exception */
        foreach ($exceptions as $exception) {
            $this->exceptions[(string)$exception->getType()] = $exception->getDescription()->render();
        }
    }

    private function parseReturnType()
    {
        $returns = $this->docBlock->getTagsByName('return');
        /** @var DocBlock\Tags\Return_|null $tag */
        $tag = array_shift($returns);

        if ($tag !== null) {
            $this->returnType = $tag->getType();
            $this->returnDescription = Parsers::$markdown->text($tag->getDescription()->render());
        } else {
            $this->returnType = new Mixed_();
            $this->returnDescription = '';
        }
    }
}

class ArgumentDocumentation implements JsonSerializable
{
    /** @var string */
    public $name;

    /** @var bool */
    public $isVariadic;

    /** @var string */
    public $description;

    /** @var mixed */
    public $defaultValue;

    /** @var string|null */
    public $defaultValueAsString;

    /** @var Type */
    public $type;

    public function __construct(Param $documentedParam, ReflectionParameter $reflectedParam = null)
    {
        $this->name = $documentedParam->getVariableName();
        $this->description = $documentedParam->getDescription();
        $this->isVariadic = $documentedParam->isVariadic();
        $this->type = $documentedParam->getType();

        if ($reflectedParam !== null && $reflectedParam->isOptional()) {
            try {
                $defaultValue = $reflectedParam->getDefaultValue();
                $this->defaultValue = $defaultValue;

                if ($defaultValue === null) {
                    $this->defaultValueAsString = 'null';
                } elseif (is_bool($defaultValue)) {
                    $this->defaultValueAsString = $defaultValue ? 'true' : 'false';
                } elseif (is_string($defaultValue)) {
                    $this->defaultValueAsString = sprintf("'%s'", $defaultValue);
                } elseif (is_array($defaultValue)) {
                    $this->defaultValueAsString = '[]';
                } else {
                    $this->defaultValueAsString = $defaultValue;
                }
            } catch (Exception $e) {
            }
        }
    }

    /**
     * @see Method
     *
     * @return array
     */
    public function getMethodArgumentDefinition()
    {
        return [
            'name' => $this->getSignature(),
            'type' => $this->type,
        ];
    }

    public function getSignature()
    {
        if ($this->defaultValueAsString) {
            return "{$this->name} = {$this->defaultValueAsString}";
        }

        if ($this->isVariadic) {
            return "{$this->name},...";
        }

        return $this->name;
    }

    public function jsonSerialize()
    {
        // TODO: Implement jsonSerialize() method.
    }
}

//
// Documentation registration
//

Parsers::setup();
$registry = new DocumentationRegistry();

// Find all registered bottomline functions
foreach (glob(dirname(__DIR__) . '/src/__/**/*.php') as $file) {
    $registry->registerDocumentationFromFile($file);
}

//
// Build our Sequence wrapper
//

function buildSequenceWrapper()
{
    global $registry;

    $methodDocs = __::chain($registry->methods)
        ->map(function (FunctionDocumentation $fxnDoc) {
            if ($fxnDoc->returnType instanceof Void_) {
                return null;
            }

            $method = $fxnDoc->asMethodTag();

            return new Method(
                $method->getMethodName(),
                __::drop($method->getArguments(), 1),
                new Object_(new Fqsen('\BottomlineWrapper')),
                true,
                $method->getDescription()
            );
        })
        ->filter()
        ->value()
    ;

    $docBlock = new DocBlock(
        'An abstract base class for documenting our sequence support',
        null,
        $methodDocs
    );
    $docBlockLiteral = writeDocBlock($docBlock);

    $filePath = dirname(__DIR__) . '/src/__/sequences/BottomlineWrapper.php';
    $fileAST = Parsers::$phpParser->parse(file_get_contents($filePath));

    /** @var Stmt\Class_ $classAST */
    $classAST = $fileAST[0];
    $classAST->setAttribute('comments', []); // Strip all comments, We'll take care of them
    $classAST->setDocComment(new Doc($docBlockLiteral));

    $phpPrinter = new Standard();
    $builtWrapper = <<<WRAPPER
<?php

// Do NOT modify this doc block, it is automatically generated.
{$phpPrinter->prettyPrint($fileAST)}
WRAPPER;

    file_put_contents($filePath, $builtWrapper . "\n");
}

buildSequenceWrapper();

//
// Build our loader
//

function buildCoreFunctionLoader()
{
    global $registry;

    $loaderFilePath = dirname(__DIR__) . '/src/__/load.php';
    $loaderAST = Parsers::$phpParser->parse(file_get_contents($loaderFilePath));
    $astNodes = &$loaderAST[0]->stmts;

    $docBlockLiteral = writeDocBlock(new DocBlock(
        '',
        null,
        __::map($registry->methods, function (FunctionDocumentation $fxnDoc) {
            return $fxnDoc->asMethodTag();
        })
    ));

    /** @var Stmt $astNode */
    foreach ($astNodes as &$astNode) {
        if ($astNode->getType() === 'Stmt_Class') {
            // We found our class definition, let's update all the @method definitions
            $astNode->setDocComment(new Doc($docBlockLiteral));
        } elseif ($astNode->getType() === 'Stmt_If') {
            $commentRegex = '/\*\*\s([a-zA-Z]+)\s+\[(\d+)\]/m';

            /** @var Comment $comment */
            $comment = __::first($astNode->getAttribute('comments'));
            $commentLiteral = preg_replace_callback($commentRegex, function ($matches) use ($registry) {
                $namespace = strtolower($matches[1]);

                return str_replace($matches[2], $registry->namespaceCount[$namespace], $matches[0]);
            }, $comment->getText());

            $commentBlock = new Comment($commentLiteral);
            $astNode->setAttribute('comments', [$commentBlock]);
        }
    }

    $phpPrinter = new Standard();
    $builtLoader = "<?php\n\n" . $phpPrinter->prettyPrint($loaderAST) . "\n";
    $builtLoader = preg_replace('/ +$/m', '', $builtLoader);
    $builtLoader = preg_replace('/(}\n)(\s+\w)/m', "$1\n$2", $builtLoader);

    file_put_contents($loaderFilePath, $builtLoader);
}

buildCoreFunctionLoader();
