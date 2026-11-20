<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Base for a ready made case.
 *
 * A case is a question a site owner actually asks, with the tool calls that
 * answer it already written down. It exists because the tools are general and
 * most people do not know what to ask for: "what can you do" deserves a better
 * answer than a list of function names.
 *
 * One file, one case. The file name is both the class name and the case id, so
 * ext/mcp/cases/old_drafts.php holds WPBE_MCP_CASE_OLD_DRAFTS and answers
 * to the id old_drafts. Nothing registers it - the loader scans the folder.
 * Deleting a case is deleting a file, and reading one means opening one file
 * rather than scrolling a list of eight.
 *
 * A case is not a new capability. Everything it does can be asked for in plain
 * words, and the agent is told to say so - the list is a starting point, not a
 * menu the site is limited to.
 *
 * steps() names the tools to call. A later step can use an earlier one's output
 * by writing @step_key.field as an argument value, which is what makes "find
 * these posts, then report on them" a single call rather than a
 * conversation.
 *
 * Cases are READ ONLY by construction: the runner refuses any tool that writes,
 * whatever a case file asks for. A prepared recipe that edits a live site
 * without the owner reading a preview first would undo the whole safety model.
 */
abstract class WPBE_MCP_CASE {

	/**
	 * @var WPBE_MCP
	 */
	protected $mcp;

	public function __construct( $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * A few words, shown in the list.
	 */
	abstract public function title();

	/**
	 * The owner's own question, in his words rather than yours.
	 */
	abstract public function question();

	/**
	 * One line on what he will be able to see or decide.
	 */
	abstract public function answers();

	/**
	 * The tool calls, in order. Each: key, tool, arguments.
	 */
	abstract public function steps();

	/**
	 * Hint for the client: table, chart_bar, chart_line, cards.
	 */
	public function render() {
		return 'table';
	}

	/**
	 * Whether this case makes sense on this site. A case that needs data the
	 * site does not keep should hide rather than answer with zeros.
	 */
	public function is_available() {
		return true;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * What this case is about, in a word or two: content, cleanup, media, seo.
	 *
	 * Lets the agent offer what fits the conversation instead of reciting the
	 * whole list.
	 */
	public function tags() {
		return array();
	}

	/**
	 * Optional. Reshapes the collected results before they are returned.
	 *
	 * This is what keeps a case from having to change a tool. A tool exists to
	 * fetch data the site holds; anything that is arithmetic on data already
	 * fetched belongs here - a count per type, a ratio between two
	 * steps, a subtotal, a sorted subset. So a new case that needs a new angle
	 * on existing numbers is still just one file dropped into this folder.
	 *
	 * A tool only has to change when the answer needs something the database
	 * was never asked for.
	 *
	 * @param array $results step key => whatever that step returned
	 * @return array
	 */
	public function derive( $results ) {
		return $results;
	}
}