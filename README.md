# tex2wp

tex2wp is a tool to simplify the conversion of existing LaTeX documents to the [eskript platform](https://eskript.ethz.ch/) of [ETH Zurich](https://www.ethz.ch/). In contrast to other LaTeX to HTML conversion tools, it's focus is not on generating output which looks as similar as possible to the PDF output as possible, but to retain as much of the semantic of the original document as possible. Language structures like commands and environments are preserved throughout the parsing process, so they can be considered when generating the final output. This allows to design documents which feel native on both paper and the web.

## Usage

    tex2wp <path to tex file>

`tex2wp` takes a tex file as its first argument and will output markup which can be put into the Wordpress editor in *text* mode.

When run using the `.phar` binary, the command has to be invoked with `php tex2wp.phar [args]`. From the source, it's `php convert.php [args]`. Run `php -d phar.readonly=false scripts/buildphar.php` to generate a phar-based binary at `bin/tex2wp`.

The markup is written to `stdout`. To save it to a file, use `tex2wp file.tex > file.txt`. On MacOS, `tex2wp file.tex | pbcopy` will put the result into the clipboard, so it can be pasted into the editor afterwards.

`\include` and `\input` commands are resolved relative to the initial `.tex` file.

### Beta Features

In addition to `.tex`, `tex2wp` now accepts an `ast` formatted in JSON as input (depending on the file extension of the input file). Since generating the `ast` might take a lot of time, this can save a lot of time during development. 

The new `--mode <mode>` argument changes the output of the script:

* `tex2wp in.tex --mode ast > ast.json` saves the `ast` for later reuse. `tex2wp ast.json` will run faster than `tex2wp in.tex`, but provide the same output.
* `tex2wp in.tex --mode pb > out.json` creates a JSON file which can be imported into [pressbooks](https://github.com/pressbooks/pressbooks) using the (not yet released) **pressbooks_import** plugin.
* `tex2wp in.tex --parts --mode pb > out.json` will convert chapters to parts and sections to chapters in pressbooks. Note that there can't be any content after a new part before the first section.
* `tex2wp in.tex --mode quiet --figarch out.zip` will create a zip archive containing all figures (but with `.pdf` replaced with `.svg`) used in the input document. This again can be imported using **pressbooks_import**.

The `pb` mode will put all sections before the first chapter into the `front-matter` and the sections inside the appendix into the `back-matter`. Chapters are converted to regular chapters. With the `--parts` option, chapters are converted to parts, and sections will be converted to chapters instead.

## High Level Architecture Overview

The TeX parser produces an [abstract syntax tree](https://en.wikipedia.org/wiki/Abstract_syntax_tree) (AST) from `.tex` source files. 

    \documentclass[12pt]{article}
    \begin{document}
    Hello world!
    \end{document}

The generating of the AST happens in several steps. The first one is done by the *lexer*, which chops the file down into several small pieces, called *tokens*.

      {"type": "cmd", "value": "\\documentclass"}
      {"type": "punct", "value": "["}
      {"type": "word", "value": "12pt"}
      {"type": "punct", "value": "]"}
      {"type": "punct", "value": "{"}
      {"type": "word", "value": "article"}
      {"type": "punct", "value": "}"}
      {"type": "space", "value": "\r\n"}
      ...

This linear token stream is passed to a *preprocessor* which creates a different linear token stream after doing some light processing like replacing makros with their definition and including external files. The new token stream is then passed to the *parser*, which will build the final tree by interpreting the individual tokens as part of language constructs like control words and environments. 

    {
      "type": "env",
      "name": "document",
      "star": false,
      "content": [
        {"type": "word","value": "Hello"},
        {"type": "space"},
        {"type": "word","value": "world"},
        {"type": "punct","value": "!"}
      ]
    }

This tree is then converted to wordpress source code in the last step. Instead of knowing every detail of the TeX language, the wordpress converter script just needs to know about some high level concepts, like paragraphs and and the meaning of text formatting commands like `\textbf`. 

## Capabilities

A minimal input document to produce output is `\begin{document}hi\end{document}`. Sections to `<h1>`, subsections to `<h2>` and so on. Chapters are converted to `<h0>`.

* Supports inline and block based LaTeX formulas as native LaTeX elements inside Wordpress. 
* Converts to following commands and environments to their corresponding HTML or Wordpress pendant (new commands can be added easily)
    * Commands:
        * \emph
        * \href
        * \par
        * \chapter
        * \\(sub)*section
        * \textbf
        * \textit
        * \textsc
    * Environments
        * enumerate
        * itemize
        * center
        * quote
* (Limited) support for figures using the `figure` environments and `\includegraphics`.
* Supports `\input` and `\include`.
* Supports `\newcommand`, `\newenvironment` and `DeclareMathOperator` to define macros. (Macros are resolved by the preprocessor, so they can even be used inside formulas.)
* Parsing of `.bib` files (when referenced using `\bibliography`) and support of `\cite`.

### Custom eskript Additions

* Supports `\label` in combination with `\ref` and `\eqref` to reference sections, figures and equations using the `[ref]` shortcode.
* Supports `\newtheorem` and puts the defined theorems into Wordpress boxes.
* Supports tagging theorem environments with `\starid` to create votable documents by using the *votingstar* Wordpress plugin.

### Conditionals and Raw Output

`%tex2ast` will be considered a comment, but the rest of the line is still interpreted. This can be used to let a document generate different content depending on whether it is interpreted using `latex` or `tex2wp`.

    \newcommand{\webonly}[1]{}
    %tex2ast \renewcommand{\webonly}[1]{#1}

will define a new command which will allow to show content only on the web, but not on paper. `%tex2ast \input{tex2wp.tex}` can be placed just before `\begin{document}` to put custom web-only modification in a separate file. 

`\UnsetCommand` and `\UnsetEnvironment` can be used to keep the preprocessor from interpreting commands or environments so they are still visible in the AST. To use the `\starid` command but ignore it on paper, use

    \newcommand{\starid}[1]{}
    %tex2ast \UnsetCommand{\starid}

`\raw` will output the first argument as-is. `\` is considered an escape character, which can be used for newline `\n`, tab `\t`, closing brackets without closing the command `\}` or a regular backslash `\\`. Other characters will just be printed unchanged when they follow the escape character. 

    \newcommand{\link}[1]{\raw{<a href="#1"><code>#1</code></a>}}

will define a new command to create a web link. 
