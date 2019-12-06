<?php
namespace Asmgit\ValidationException;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Query2ValidationException extends QueryException
{
    // all errors: https://dev.mysql.com/doc/refman/8.0/en/server-error-reference.html
    const ER_BAD_NULL_ERROR = 1048; // Column '%s' cannot be null
    const ER_DUP_ENTRY = 1062; // Duplicate entry '%s' for key %d
    const ER_DATA_TOO_LONG = 1406; // Data too long for column '%s' at row %ld
    // Enum fields 2 "in" validation
    const WARN_DATA_TRUNCATED = 1265; // Data truncated for column '%s' at row %ld
    // Different types
    const ER_TRUNCATED_WRONG_VALUE_FOR_FIELD = 1366; // Incorrect %s value: '%s' for column '%s' at row %ld

    protected $type;

    protected $paramFieldName = 'attribute';

    protected $fieldName;

    protected $params;

    protected $dbErrorMessage;

    public $errorMessage;

    public static $messageTemplates = null;

    public static $customValidationMessages = null;

    public function __construct($exception)
    {
        parent::__construct($exception->getSql(), $exception->getBindings(), $exception);
        if (self::$customValidationMessages == null) {
            self::$customValidationMessages = [];
        }
        $this->type = $this->errorInfo[1];
        $this->dbErrorMessage = $this->errorInfo[2];
        self::$messageTemplates = self::getMessageTemplates();
    }

    private static function supportedTypes() {
        $types = [];
        foreach (self::getMessageTemplates() as $type => $msg) {
            $types[] = $type;
        }
        return $types;
    }

    public static function check($exception) {
        return ($exception instanceof QueryException) && in_array($exception->errorInfo[1], self::supportedTypes());
    }

    public static function getMessageTemplates()
    {
        if (self::$messageTemplates != null) return self::$messageTemplates;

        $messageTemplates = [];
        $messageTemplates[self::ER_BAD_NULL_ERROR] = [
            'orig' => "Column '(.*?)' cannot be null",
            'new' => Lang::get('validation.required'),
            'params' => [1 => 'attribute']
        ];
        $messageTemplates[self::ER_DUP_ENTRY] = [
            'orig' => "Duplicate entry '(.*?)' for key '(.*?)'",
            'new' => Lang::get('validation.unique'),
            'params' => [1 => 'value', 2 => 'index_name'],
            'params_post_process' => function(&$ex) {
                // get field name from unique constraint key name. Example: users_email_unique
                try {
                    $q = DB::select("SELECT GROUP_CONCAT(S.COLUMN_NAME ORDER BY S.SEQ_IN_INDEX) col_names
                    , MAX(S.TABLE_NAME) table_name
                    , MAX(S.INDEX_COMMENT) index_comment
                    FROM INFORMATION_SCHEMA.STATISTICS S
                    WHERE S.TABLE_SCHEMA = DATABASE()
                     AND S.INDEX_NAME = :index_name",
                        ['index_name' => $ex->params['index_name']]
                    )[0];
                } catch (\Exception $e) {
                    throw new \Exception($e);
                }
                $ex->params['attribute'] = $q->col_names;
                $ex->params['table_name'] = $q->table_name;
                $ex->errorMessage = $q->index_comment ?: $ex->errorMessage;
            }
        ];
        $messageTemplates[self::ER_DATA_TOO_LONG] = [
            'orig' => "Data too long for column '(.*?)' at row ([0-9]+)",
            'new' => 'The :attribute is too long.',
            'params' => [1 => 'attribute', 2 => 'rownum']
        ];
        $messageTemplates[self::WARN_DATA_TRUNCATED] = [
            'orig' => "Data truncated for column '(.*?)' at row ([0-9]+)",
            'new' => Lang::get('validation.in'),
            'params' => [1 => 'attribute', 2 => 'rownum']
        ];
        $messageTemplates[self::ER_TRUNCATED_WRONG_VALUE_FOR_FIELD] = [
            'orig' => "Incorrect (.*?) value: '(.*?)' for column '(.*?)' at row ([0-9]+)",
            'new' => 'The :attribute must be an :field_type type.',
            'params' => [1 => 'field_type', 2 => 'value', 3 => 'attribute', 4 => 'rownum']
        ];
        return $messageTemplates;
    }

    private function processMessage()
    {
        $msg = self::$messageTemplates[$this->type];
        $this->errorMessage = $msg['new'];
        $this->paramFieldName = $msg['field'] ?? $this->paramFieldName;
        preg_match('~' . $msg['orig'] . '~', $this->dbErrorMessage, $matches);
        foreach ($msg['params'] as $matchIndex => $paramName) {
            $this->params[$paramName] = $matches[$matchIndex];
        }
        if (isset($msg['params_post_process'])) {
            $msg['params_post_process']($this);
        }
        $this->fieldName = $this->params[$this->paramFieldName];

        foreach (self::$customValidationMessages as $validation) {
            if ($validation['errorType'] == $this->type
                && ($validation['field'] == $this->fieldName || $validation['field'] == null)
            ) {
                $this->fieldName = $validation['newField'] ?? $this->fieldName;
                $this->errorMessage = $validation['message'];
                break;
            }
        }

        $this->errorMessage = $this->makeReplacements($this->errorMessage, $this->params);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render()
    {
        $this->processMessage();
        // explode fields name by ,
        $msg = [];
        $fields = explode(',', $this->fieldName);
        foreach ($fields as $field) {
            $msg[$field] = [$this->errorMessage];
        }
        throw ValidationException::withMessages($msg);
    }

    public static function addValidationMessage($message, $errorType, $field = null, $newField = null)
    {
        if (self::$customValidationMessages == null) {
            self::$customValidationMessages = [];
        }
        self::$customValidationMessages[] = ['message' => $message,
            'errorType' => $errorType,
            'field' => $field,
            'newField' => $newField
        ];
    }

    /**
     * copy paste
     * Illuminate\Translation\Translator->makeReplacements
     *
     * Make the place-holder replacements on a line.
     *
     * @param  string  $line
     * @param  array   $replace
     * @return string
     */
    protected function makeReplacements($line, array $replace)
    {
        if (empty($replace)) {
            return $line;
        }

        $replace = $this->sortReplacements($replace);

        foreach ($replace as $key => $value) {
            $line = str_replace(
                [':'.$key, ':'.Str::upper($key), ':'.Str::ucfirst($key)],
                [$value, Str::upper($value), Str::ucfirst($value)],
                $line
            );
        }

        return $line;
    }

    /**
     * copy paste
     * Illuminate\Translation\Translator->sortReplacements
     *
     * Sort the replacements array.
     *
     * @param  array  $replace
     * @return array
     */
    protected function sortReplacements(array $replace)
    {
        return (new Collection($replace))->sortBy(function ($value, $key) {
            return mb_strlen($key) * -1;
        })->all();
    }
}
