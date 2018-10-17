<?php
declare(strict_types = 1);

namespace Psalm\LanguageServer\Server;

use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\{
    Node,
    NodeTraverser
};
use Psalm\LanguageServer\{
    LanguageServer,
    LanguageClient,
    PhpDocumentLoader,
    PhpDocument,
    DefinitionResolver,
    CompletionProvider
};
use Psalm\LanguageServer\NodeVisitor\VariableReferencesCollector;
use LanguageServerProtocol\{
    SymbolLocationInformation,
    SymbolDescriptor,
    TextDocumentItem,
    TextDocumentIdentifier,
    VersionedTextDocumentIdentifier,
    Position,
    Range,
    FormattingOptions,
    TextEdit,
    Location,
    SymbolInformation,
    ReferenceContext,
    Hover,
    MarkedString,
    SymbolKind,
    CompletionItem,
    CompletionItemKind
};
use Psalm\Codebase;
use Psalm\LanguageServer\Index\ReadableIndex;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ClassLikeChecker;
use Sabre\Event\Promise;
use Sabre\Uri;
use function Sabre\Event\coroutine;
use function Psalm\LanguageServer\{waitForEvent, isVendored};

/**
 * Provides method handlers for all textDocument/* methods
 */
class TextDocument
{
    /**
     * @var LanguageServer
     */
    protected $server;

    /**
     * @var Codebase
     */
    protected $codebase;

    public function __construct(
        LanguageServer $server,
        Codebase $codebase
    ) {
        $this->server = $server;
        $this->codebase = $codebase;
    }

    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document's truth is now managed by the client and the server must not try to read the document's truth using the
     * document's uri.
     *
     * @param \LanguageServerProtocol\TextDocumentItem $textDocument The document that was opened.
     * @return void
     */
    public function didOpen(TextDocumentItem $textDocument)
    {
        $file_path = LanguageServer::uriToPath($textDocument->uri);

        $this->server->invalidateFileAndDependents($textDocument->uri);

        $this->server->analyzePath($file_path);
        $this->server->emitIssues($textDocument->uri);
    }

    /**
     * @return void
     */
    public function didSave(TextDocumentItem $textDocument)
    {
        $file_path = LanguageServer::uriToPath($textDocument->uri);

        $this->server->invalidateFileAndDependents($textDocument->uri);

        $this->server->analyzePath($file_path);
        $this->server->emitIssues($textDocument->uri);
    }

    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param \LanguageServerProtocol\VersionedTextDocumentIdentifier $textDocument
     * @param \LanguageServerProtocol\TextDocumentContentChangeEvent[] $contentChanges
     * @return void
     */
    public function didChange(VersionedTextDocumentIdentifier $textDocument, array $contentChanges)
    {
    }

    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document's truth now exists where the document's uri points to (e.g. if the document's uri is a file uri the
     * truth now exists on disk).
     *
     * @param \LanguageServerProtocol\TextDocumentIdentifier $textDocument The document that was closed
     * @return void
     */
    public function didClose(TextDocumentIdentifier $textDocument)
    {
    }


    /**
     * The goto definition request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Location|Location[]>
     */
    public function definition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(
            /**
             * @return \Generator<int, true, mixed, Hover|Location>
             */
            function () use ($textDocument, $position) {
                if (false) {
                    yield true;
                }

                $file_path = LanguageServer::uriToPath($textDocument->uri);

                $file_contents = $this->codebase->getFileContents($file_path);

                $offset = $position->toOffset($file_contents);

                list($reference_map) = $this->server->getMapsForPath($file_path);

                $reference = null;

                if (!$reference_map) {
                    return new Hover([]);
                }

                foreach ($reference_map as $start_pos => list($end_pos, $possible_reference)) {
                    if ($offset < $start_pos) {
                        break;
                    }

                    if ($offset > $end_pos + 1) {
                        continue;
                    }

                    $reference = $possible_reference;
                }

                if ($reference === null) {
                    return new Hover([]);
                }

                $code_location = $this->codebase->getSymbolLocation($file_path, $reference);

                if (!$code_location) {
                    return new Hover([]);
                }

                return new Location(
                    LanguageServer::pathToUri($code_location->file_path),
                    new Range(
                        new Position($code_location->getLineNumber() - 1, $code_location->getColumn() - 1),
                        new Position($code_location->getEndLineNumber() - 1, $code_location->getEndColumn() - 1)
                    )
                );
            }
        );
    }

    /**
     * The hover request is sent from the client to the server to request
     * hover information at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Hover>
     */
    public function hover(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(
            /**
             * @return \Generator<int, true, mixed, Hover>
             */
            function () use ($textDocument, $position) {
                if (false) {
                    yield true;
                }

                $file_path = LanguageServer::uriToPath($textDocument->uri);

                $file_contents = $this->codebase->getFileContents($file_path);

                $offset = $position->toOffset($file_contents);

                list($reference_map) = $this->server->getMapsForPath($file_path);

                error_log(json_encode($reference_map));

                $reference = null;

                if (!$reference_map) {
                    return new Hover([]);
                }

                $start_pos = null;
                $end_pos = null;

                foreach ($reference_map as $start_pos => list($end_pos, $possible_reference)) {
                    if ($offset < $start_pos) {
                        break;
                    }

                    if ($offset > $end_pos + 1) {
                        continue;
                    }

                    $reference = $possible_reference;
                }

                if ($reference === null || $start_pos === null || $end_pos === null) {
                    return new Hover([]);
                }

                $range = new Range(
                    self::getPositionFromOffset($start_pos, $file_contents),
                    self::getPositionFromOffset($end_pos, $file_contents)
                );

                $contents = [];
                $contents[] = new MarkedString(
                    'php',
                    "<?php\n" . $this->codebase->getSymbolInformation($file_path, $reference)
                );

                return new Hover($contents, $range);
            }
        );
    }

    private static function getPositionFromOffset(int $offset, string $file_contents): Position
    {
        $file_contents = substr($file_contents, 0, $offset);
        return new Position(
            substr_count("\n", $file_contents),
            $offset - (int)strrpos($file_contents, "\n", strlen($file_contents))
        );
    }

    /**
     * The Completion request is sent from the client to the server to compute completion items at a given cursor
     * position. Completion items are presented in the IntelliSense user interface. If computing full completion items
     * is expensive, servers can additionally provide a handler for the completion item resolve request
     * ('completionItem/resolve'). This request is sent when a completion item is selected in the user interface. A
     * typically use case is for example: the 'textDocument/completion' request doesn't fill in the documentation
     * property for returned completion items since it is expensive to compute. When the item is selected in the user
     * interface then a 'completionItem/resolve' request is sent with the selected completion item as a param. The
     * returned completion item should have the documentation property filled in.
     *
     * @param TextDocumentIdentifier The text document
     * @param Position $position The position
     * @return Promise <CompletionItem[]|CompletionList>
     */
    public function completion(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(
            /**
             * @return \Generator<int, true, mixed, array<empty, empty>>
             */
            function () use ($textDocument, $position) {
                if (false) {
                    yield true;
                }

                $file_path = LanguageServer::uriToPath($textDocument->uri);

                $file_contents = $this->codebase->getFileContents($file_path);

                $offset = $position->toOffset($file_contents);

                list(, $type_map) = $this->server->getMapsForPath($file_path);

                if (!$type_map) {
                    return [];
                }

                $recent_type = null;
                $start_pos = null;
                $end_pos = null;

                $reversed_type_map = array_reverse($type_map, true);

                foreach ($reversed_type_map as $start_pos => list($end_pos, $possible_type)) {
                    $recent_type = $possible_type;

                    if ($offset < $start_pos) {
                        continue;
                    }

                    if ($offset > $end_pos + 1) {
                        break;
                    }
                }

                if (!$recent_type
                    || $recent_type === 'mixed'
                    || $end_pos === null
                    || $start_pos === null
                ) {
                    return [];
                }

                $gap = substr($file_contents, $end_pos + 1, $offset - $end_pos - 1);

                error_log('type is ' . $recent_type . ' at ' . $offset . ' ' . $gap);

                $completion_items = [];

                if ($gap === '->') {
                    try {
                        $class_storage = $this->codebase->classlike_storage_provider->get($recent_type);

                        foreach ($class_storage->appearing_method_ids as $declaring_method_id) {
                            $method_storage = $this->codebase->methods->getStorage($declaring_method_id);

                            $completion_items[] = new CompletionItem(
                                (string)$method_storage,
                                CompletionItemKind::METHOD,
                                null,
                                null,
                                null,
                                null,
                                $method_storage->cased_name . '()'
                            );
                        }

                        foreach ($class_storage->declaring_property_ids as $property_name => $declaring_property_id) {
                            $property_storage = $this->codebase->properties->getStorage($declaring_property_id);

                            $completion_items[] = new CompletionItem(
                                $property_storage->getInfo() . ' $' . $property_name,
                                CompletionItemKind::PROPERTY,
                                null,
                                null,
                                null,
                                null,
                                $property_name
                            );
                        }
                    } catch (\Exception $e) {
                        error_log($e->getMessage());
                        return [];
                    }
                }

                return $completion_items;
            }
        );
    }
}
