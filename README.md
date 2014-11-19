Sometimes you encounter a WordPress codebase and you're not sure
if the plugins installed in it have been modified.

This is a simple [WP-CLI](http://wp-cli.org/) command for running diff between installed
plugins and the source code freely available on WordPress.org.

To use the command, clone this repo, and then import the dependencies
using composer:

    path/to/plugin-diff-command> composer update

Then run WP-CLI like so:

    .> wp --require=path/to/plugin-diff-command/plugin-diff-command.php plugin diff <plugin-name>

The default output reports any files whose MD5 checksum do not match
the checksum of the same files hosted on WordPress.org. It also
reports missing files, and paths that are mistmatched (directory vs. file).

You can get a unified diff report by including the CLI option `--report=unified`.

## Want to contribute?

Please do! Just fork, modify, and send a pull request. Thank you!

The diff output is provided by [php-diff](https://github.com/chrisboulton/php-diff).
That library doesn't appear to be able to ignore differences that arise
from different line endings&mdash;anyone willing to take on that issue
would be a welcome addition to our team.

## License

MIT Licensed. Copyright &copy; 2014 Fat Panda, LLC. Enjoy the freedom!

## Who is Fat Panda?

[Fat Panda](http://fatpandadev.com) is a digital agency in Winchester, VA.
