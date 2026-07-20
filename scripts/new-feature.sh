#!/bin/bash

# IoT Portal - Feature Branch Helper
# This script helps create properly formatted feature branches

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 IoT Portal - Feature Branch Creator${NC}"
echo ""

# Check if we're in git repo
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}❌ Not a git repository${NC}"
    exit 1
fi

# Check if we're on main
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo -e "${YELLOW}⚠️  You're currently on branch: $CURRENT_BRANCH${NC}"
    read -p "Switch to main? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git checkout main
    else
        echo -e "${RED}❌ Aborted${NC}"
        exit 1
    fi
fi

# Pull latest changes
echo -e "${BLUE}📥 Pulling latest changes from origin/main...${NC}"
git pull origin main

# Get issue number
echo ""
read -p "Enter issue number (e.g., 1 for US-1): " ISSUE_NUM

# Validate issue number
if ! [[ "$ISSUE_NUM" =~ ^[0-9]+$ ]]; then
    echo -e "${RED}❌ Invalid issue number${NC}"
    exit 1
fi

# Get branch slug
echo ""
echo "Enter branch slug (kebab-case, e.g., 'device-types'):"
read -p "> " BRANCH_SLUG

# Validate slug
if ! [[ "$BRANCH_SLUG" =~ ^[a-z0-9-]+$ ]]; then
    echo -e "${RED}❌ Invalid slug. Use only lowercase letters, numbers, and hyphens${NC}"
    exit 1
fi

# Construct branch name
BRANCH_NAME="feature/us-${ISSUE_NUM}-${BRANCH_SLUG}"

# Confirm
echo ""
echo -e "${YELLOW}Branch to create: ${GREEN}$BRANCH_NAME${NC}"
read -p "Create this branch? (y/n) " -n 1 -r
echo

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 1
fi

# Create and checkout branch
git checkout -b "$BRANCH_NAME"

echo ""
echo -e "${GREEN}✅ Branch created successfully!${NC}"
echo ""
echo -e "${BLUE}📋 Next steps:${NC}"
echo -e "  1. Make your changes"
echo -e "  2. Commit with: ${YELLOW}git commit -m \"fix: your description\"${NC}"
echo -e "  3. Push with: ${YELLOW}git push origin $BRANCH_NAME${NC}"
echo ""
echo -e "${BLUE}📚 See CONTRIBUTING.md for full workflow${NC}"
