@symfony-common
Feature: Taint sources and sinks of Symfony components

  Background:
    Given I have issue handlers "UnusedVariable,UnusedClass,UnusedReturnValue,MixedArgument,MixedAssignment,PossiblyNullArgument" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
      use Symfony\Component\Filesystem\Filesystem;
      use Symfony\Component\Form\FormInterface;
      use Symfony\Component\HttpFoundation\File\UploadedFile;
      use Symfony\Component\HttpFoundation\RedirectResponse;
      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\Mime\Email;
      use Symfony\Component\Process\Process;
      use Symfony\Contracts\HttpClient\HttpClientInterface;
      use Twig\Environment;

      /**
       * @psalm-taint-source input
       * @psalm-suppress InvalidReturnType
       */
      function userInput(): string {}
      """

  Scenario Outline: User input reaches a Symfony sink
    Given I have the following code
      """
      function run(Filesystem $filesystem, HttpClientInterface $httpClient, Environment $twig): void
      {
        $input = userInput();
        <code>;
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type   | Message   |
      | <type> | <message> |
    And I see no other errors
    Examples:
      | code                                           | type          | message                                         |
      | Process::fromShellCommandline($input)          | TaintedShell  | Detected tainted shell code                     |
      | $filesystem->dumpFile($input, 'content')       | TaintedFile   | Detected tainted file handling                  |
      | $filesystem->readFile($input)                  | TaintedFile   | Detected tainted file handling                  |
      | $filesystem->remove($input)                    | TaintedFile   | Detected tainted file handling                  |
      | $filesystem->rename('/tmp/a', $input)          | TaintedFile   | Detected tainted file handling                  |
      | (new Email())->html($input)                    | TaintedHtml   | Detected tainted HTML                           |
      | $httpClient->request('GET', $input)            | TaintedSSRF   | Detected tainted network request                |
      | new RedirectResponse($input)                   | TaintedHeader | Detected tainted header                         |
      | (new RedirectResponse('/'))->setTargetUrl($input) | TaintedHeader | Detected tainted header                      |
      | $twig->createTemplate($input)                  | TaintedEval   | Detected tainted code passed to eval or similar |

  Scenario: A controller redirects to user input
    Given I have the following code
      """
      final class MyController extends AbstractController
      {
        public function __invoke(): Response
        {
          return $this->redirect(userInput());
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors

  Scenario Outline: User input given safely to Symfony is not reported
    Given I have the following code
      """
      function run(Filesystem $filesystem, HttpClientInterface $httpClient): void
      {
        $input = userInput();
        <code>;
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
    Examples:
      | code                                                |
      | new Process(['ls', $input])                         |
      | $filesystem->dumpFile('/tmp/file', $input)          |
      | (new Email())->text($input)                         |
      | $httpClient->request('GET', '/search', ['query' => ['q' => $input]]) |

  Scenario Outline: A Symfony source reaches a sink
    Given I have the following code
      """
      /** @param FormInterface<mixed> $form */
      function run(Request $request, UploadedFile $file, FormInterface $form): void
      {
        new Response(var_export(<source>, true));
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors
    Examples:
      | source                             |
      | $file->getClientOriginalName()     |
      | $file->getClientOriginalExtension() |
      | $file->getClientMimeType()         |
      | $request->getContent()             |
      | $request->toArray()                |
      | $request->getRequestUri()          |
      | $request->getPathInfo()            |
      | $request->getQueryString()         |
      | $request->getUri()                 |
      | $form->getData()                   |
      | $form->getViewData()               |
