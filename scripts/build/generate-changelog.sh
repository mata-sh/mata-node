#!/bin/sh

# Git colors for user output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

echo -e "${CYAN}Generating changelog...${NC}"

LAST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "HEAD~10")
DIFF=$(git diff $LAST_TAG..HEAD)

if [ -n "$DIFF" ]; then
   # Get first 3 lines of changelog for reference
   CHANGELOG_HEADER=$(head -n 3 CHANGELOG.md)
   
   # Generate the changelog content with version and annotation suggestions
   CLAUDE_RESPONSE=$(echo "$DIFF" | claude -p "Current git tag: $LAST_TAG

Here's the current changelog header for reference:
$CHANGELOG_HEADER

Based on this diff and the current tag ($LAST_TAG), provide:
1. FIRST LINE: Next version number (just the version like '2.4.0' or '3.0.0')
2. SECOND LINE: Git tag annotation in format: 'Level: Main Areas' (e.g., 'Minor: Unit Tests & Mailer')

Consider for versioning (Semantic Versioning):
- PATCH (2.3.8): Bug fixes, small improvements, backwards compatible
- MINOR (2.4.0): New features, but backwards compatible  
- MAJOR (3.0.0): Breaking changes that require user code changes

For annotation format:
- Use 'Patch:', 'Minor:', or 'Major:' prefix
- List 1-3 main affected areas/classes (e.g., 'Database & Logger', 'TemplateEngine', 'Unit Tests & Mailer')
- Keep total annotation under 50 characters
- Examples: 'Minor: TemplateEngine & Tests', 'Patch: Mailer validation', 'Major: Database API redesign'

Then output a changelog section in this exact format:

### Breaking Changes
- [list any breaking changes or 'None']

### Added  
- [list new features/classes/methods]

### Fixed
- [list important bug fixes]

Do not ask questions. Do not explain. Just output version number first, tag annotation second, then changelog entries. Be concise one line per change max. Skip trivial changes.")

   # Extract the first line (version), second line (annotation), and the rest (changelog)
   SUGGESTED_VERSION=$(echo "$CLAUDE_RESPONSE" | sed -n '1p')
   SUGGESTED_ANNOTATION=$(echo "$CLAUDE_RESPONSE" | sed -n '2p')
   CHANGELOG_CONTENT=$(echo "$CLAUDE_RESPONSE" | tail -n +3)
   
   # Show suggestion and prompt for tag annotation
   echo -e "${NC}Suggested annotation: "
   echo -e "${CYAN}$SUGGESTED_ANNOTATION${NC}"
   printf "${NC}Enter tag annotation (Enter to use suggestion): ${NC}"
   read USER_ANNOTATION
   
   # Use suggestion if user input is empty
   if [ -z "$USER_ANNOTATION" ]; then
       TAG_ANNOTATION="$SUGGESTED_ANNOTATION"
   else
       TAG_ANNOTATION="$USER_ANNOTATION"
   fi

   # Show suggestions and prompt for version
   echo -e "${NC}Current tag: ${BOLD}$LAST_TAG${NC}"
   echo -e "${NC}Suggested version: ${CYAN}$SUGGESTED_VERSION${NC}"
   printf "${NC}Enter version (Enter to use suggestion): ${NC}"
   read USER_VERSION
   
   # Use suggestion if user input is empty
   if [ -z "$USER_VERSION" ]; then
       NEXT_VERSION="$SUGGESTED_VERSION"
   else
       NEXT_VERSION="$USER_VERSION"
   fi
   

   TODAY=$(date +"%Y-%m-%d")
   
   # Create temp file with new entry
   cat > /tmp/new_changelog_entry << EOF
## [$NEXT_VERSION] - $TODAY
$CHANGELOG_CONTENT

EOF
   
   # Insert the new entry after "# Changelog"
   sed -i '1 r /tmp/new_changelog_entry' CHANGELOG.md
   rm /tmp/new_changelog_entry
   
   # Open changelog in editor for manual review/editing
   echo -e "${CYAN}Opening CHANGELOG.md in editor for review...${NC}"
   ${EDITOR:-nano} CHANGELOG.md
   
   # Ask if user wants to commit the changes
   echo ""
   echo -e "${MAGENTA}Changelog content:${NC}"
   head -n 20 CHANGELOG.md
   echo ""
   printf "${NC}Commit changelog and create tag ${CYAN}$NEXT_VERSION${NC}${NC}? [Y/n]: ${NC}"
   read CONFIRM
   
   if [ "$CONFIRM" = "n" ] || [ "$CONFIRM" = "N" ]; then
       echo -e "${RED}Aborted. Changelog updated but not committed.${NC}"
       exit 1
   fi
   
   # Commit the changelog
   git add CHANGELOG.md
   git commit -m "chore: update CHANGELOG.md for version $NEXT_VERSION"
   
   # Create annotated git tag with custom annotation
   git tag -a $NEXT_VERSION -m "$TAG_ANNOTATION"
   
   echo -e "${GREEN}Changelog committed and git tag created:"
   echo -e "${GREEN}$NEXT_VERSION${NC}"
   echo -e "${GREEN}$TAG_ANNOTATION${NC}"
else
   echo -e "${YELLOW}No changes since last tag${NC}"
fi
