# What is Query2ValidationException for Laravel 5?
Query2ValidationException throw mysql QueryException which relate to business logic to ValidationException.
For example:
500 Column ':attribute' cannot be null
to
422 The :attribute field is required.

# Install
```bash
composer require asmgit/query2validation-exception
```

# Quick start
app/Exception/Handler.php
```php
use Asmgit\Validation\Exception;
...
public function report(Exception $exception)
{
    if (Query2ValidationException::check($exception)) {
        $this->dontReport[] = QueryException::class;
        return (new Query2ValidationException($exception))->render();
    }
...
```
Now you can remove most common Validation's from your code and DB will take care of your data.

# What exctepions catch Query2ValidationException
After install most common exception will translate to ValidationException:
* ER_BAD_NULL_ERROR, 1048, (Column '%s' cannot be null) to The :attribute field is required.
* ER_DUP_ENTRY, 1062, (Duplicate entry '%s' for key %d) to The :attribute has already been taken.
* ER_DATA_TOO_LONG, 1406, (Data too long for column '%s' at row %ld) to  The :attribute is too long.
* WARN_DATA_TRUNCATED, 1265, (Data truncated for column '%s' at row %ld) to The selected :attribute is invalid.
* ER_TRUNCATED_WRONG_VALUE_FOR_FIELD. 1366, (Incorrect %s value: '%s' for column '%s' at row %ld) to The :attribute must be an :field_type type.

You can get all builtin messages
```php
dd(Query2ValidationException::getMessageTemplates());
```

# How set custom message for exception
```php
Query2ValidationException::addValidationMessage(
    'Please, fill your first name.', // new message
    Query2ValidationException::ER_BAD_NULL_ERROR, // error type
    'first_name' // field
);
$user->save();
array_pop(Query2ValidationException::$customValidationMessages);
```
will return for ajax request
```json
{"message":"The given data was invalid."
,"errors":{"first_name":["Please, fill your first name."]}
}
```

# How work composite unique key exception
Messages will generate for all fields in unique key
```mysql
ALTER TABLE users
ADD UNIQUE INDEX users_account_id_full_name_unique (account_id,full_name)
```
```json
{"message":"The given data was invalid."
,"errors":{"account_id":["The account_id,full_name has already been taken."]
    ,"full_name":["The account_id,full_name has already been taken."]
}}
```

# How set custom message for composite unique key exception
## One way:
Declare COMMENT for unique key
```mysql
ALTER TABLE users
DROP INDEX users_account_id_full_name_unique
, ADD UNIQUE INDEX users_account_id_full_name_unique (account_id,full_name)
  COMMENT 'User must be unique for each account. :value has already been taken.'
```
```json
{"message":"The given data was invalid."
,"errors":{"account_id":["User must be unique for each account. 1-John Smith has already been taken."]
    ,"full_name":["User must be unique for each account. 1-John Smith has already been taken."]
}}
```
You can use params such :value. See all params:
```php
dd(Query2ValidationException::getMessageTemplates());
```

## Another way (with change field destination):
```php
public function store(Request $request)
{
    ...
    Query2ValidationException::addValidationMessage(
        'User already exists in this account.', // new message
        Query2ValidationException::ER_DUP_ENTRY, // error type
        'account_id,full_name', // all fields in composite unique key
        'first_name,last_name' // new field/fields
    );
    $user->save();
    array_pop(Query2ValidationException::$customValidationMessages);
...
public function update($id, Request $request)
{
    ...
    Query2ValidationException::addValidationMessage(
        'User already exists in this account.', // new message
        Query2ValidationException::ER_DUP_ENTRY, // error type
        'account_id,full_name', // all fields in composite unique key
        'first_name,last_name' // new field/fields
    );
    $user->save();
    array_pop(Query2ValidationException::$customValidationMessages);
```
```json
{"message":"The given data was invalid."
,"errors":{"first_name":["User already exists in this account."]
    ,"last_name":["User already exists in this account."]
}}
```

# How catch new global mysql exception
* check error format in mysql doc site:
https://dev.mysql.com/doc/refman/8.0/en/server-error-reference.html
* Intitialize builtin messages
```php
Query2ValidationException::$messageTemplates = Query2ValidationException::getMessageTemplates();
```
* create new message template
```php
Query2ValidationException::$messageTemplates[<errorNo>] = ...
```
* also you can change builtin messages

Example for mysql >= 8.0.16 with check constraint support:
app/Exception/Handler.php
```php
use Asmgit\Validation\Exception;
...
public function report(Exception $exception)
{
    // setup builtin messageTemplates
    Query2ValidationException::$messageTemplates = Query2ValidationException::getMessageTemplates();
    // Error number: 3819; Symbol: ER_CHECK_CONSTRAINT_VIOLATED; SQLSTATE: HY000 Message: Check constraint '%s' is violated.
    Query2ValidationException::$messageTemplates[3819] = [
        'orig' => "Check constraint '(.*?)\\|(.*?)\\|(.*)' is violated.",
        'new' => "Check constraint is violated: :message",
        'params' => [1 => 'table_name', 2 => 'attribute', 3 => 'message']
    ];
    if (Query2ValidationException::check($exception)) {
        $this->dontReport[] = QueryException::class;
        return (new Query2ValidationException($exception))->render();
    }
...
```
Create and raise any CHECK CONSTRAINT
```mysql
ALTER TABLE users
ADD CONSTRAINT `users|first_name,last_name|first name must be longer than last name.` CHECK (first_name > last_name);
```
```json
{"message":"The given data was invalid."
,"errors":{"first_name":["Check constraint is violated: first name must be longer than last name."]
    ,"last_name":["Check constraint is violated: first name must be longer than last name."]
}}
```

# What are the disadvantages of Query2ValidationException solution?
* Mysql raise only one first error.

# What are the advantages of Query2ValidationException solution?
* You can describe constraints only in UI and DB, against: UI, DB, app->store, app->update rules.
* Some exception you can't catch in app layer. For example unique constraints.
This code work fine in oneuser application.
```php
public function store(Request $request)
{
    $validatedData = $request->validate([
        'full_name' => 'required|unique:users',
    ]);
    sleep(20);
    $user->full_name = $request->input('full_name');
    $user->save();
```
But if you run this code with new unique full_name twice, one session save data, second get 500 exception (with correct unique constraint DB config, without this you get BSOD ))))