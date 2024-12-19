# php-gpt-context-generator
Create prompt context of your project according to chosen tables and files


Installation
### `composer require --dev iamserjo/php-gpt-context-generator`


Run
### `php artisan _dev:gpt-project-context-generator`



### Short Documentation
1.	This Laravel command generates a single prompt file with the selected database tables’ schema and chosen project files.
2.	It helps provide GPT models with relevant context for coding tasks, speeding up development.
3.	Installation is simple: run `composer require --dev iamserjo/php-gpt-context-generator`.
4.	Use `php artisan _dev:gpt-project-context-generator` to launch the interactive console.
5.	First, select an existing setup or create a new one.
6.	Next, choose tables to include, then pick files by typing partial filenames.
7.	The command creates a timestamped .txt file in storage/app/gpt-context-generator.
8.	This file contains your database schema, file contents, and a brief prompt.
9.	Reuse or edit previous setups by loading from history.
10.	Leverage the generated context file as input to GPT tools for better code suggestions.