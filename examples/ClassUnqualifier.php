<?php
namespace Psalm\Example\Plugin;

use Psalm\CodeLocation;
use Psalm\FileManipulation\FileManipulation;
use Psalm\StatementsSource;
use Psalm\Type;

class ClassUnqualifier extends \Psalm\Plugin
{
    /**
     * @param  string             $fqClassName
     * @param  FileManipulation[] $fileReplacements
     *
     * @return void
     */
    public static function afterClassLikeExistsCheck(
        StatementsSource $statementsSource,
        $fqClassName,
        CodeLocation $codeLocation,
        array &$fileReplacements = []
    ) {
        $candidateType = $codeLocation->getSelectedText();
        $aliases = $statementsSource->getAliasedClassesFlipped();

        if ($statementsSource->getFileChecker()->getFilePath() !== $codeLocation->filePath) {
            return;
        }

        if (strpos($candidateType, '\\' . $fqClassName) !== false) {
            $typeTokens = Type::tokenize($candidateType, false);

            foreach ($typeTokens as &$typeToken) {
                if ($typeToken === ('\\' . $fqClassName)
                    && isset($aliases[strtolower($fqClassName)])
                ) {
                    $typeToken = $aliases[strtolower($fqClassName)];
                }
            }

            $newCandidateType = implode('', $typeTokens);

            if ($newCandidateType !== $candidateType) {
                $bounds = $codeLocation->getSelectionBounds();
                $fileReplacements[] = new FileManipulation($bounds[0], $bounds[1], $newCandidateType);
            }
        }
    }
}
