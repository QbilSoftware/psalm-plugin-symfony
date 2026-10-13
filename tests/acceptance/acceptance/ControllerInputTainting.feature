@symfony-common
Feature: Taints of the input Symfony maps to controller arguments

  Background:
    Given I have issue handlers "UnusedVariable,UnusedClass,UnusedReturnValue,PropertyNotSetInConstructor,MissingConstructor" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      namespace App;

      use Symfony\Component\Form\AbstractType;
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
      use Symfony\Component\HttpKernel\Attribute\MapQueryString;
      use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
      use Symfony\Component\OptionsResolver\OptionsResolver;
      use Symfony\Component\Routing\Attribute\Route;

      final class Address
      {
        public string $street = '';
      }

      final class Payload
      {
        public string $name = '';
        public int $count = 0;
        public ?Address $address = null;
      }
      """

  Scenario: A route placeholder is printed in a response
    Given I have the following code
      """
      final class Controller
      {
        #[Route('/hello/{name}')]
        public function hello(string $name): Response
        {
          return new Response($name);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario: A placeholder of an invokable controller with the route on the class is printed in a response
    Given I have the following code
      """
      #[Route('/hello/{name}')]
      final class Controller
      {
        public function __invoke(string $name): Response
        {
          return new Response($name);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario: A query parameter is printed in a response
    Given I have the following code
      """
      final class Controller
      {
        #[Route('/search')]
        public function search(#[MapQueryParameter] string $query): Response
        {
          return new Response($query);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario Outline: A property of a mapped request object is printed in a response
    Given I have the following code
      """
      final class Controller
      {
        #[Route('/payload')]
        public function payload(#[<attribute>] Payload $payload): Response
        {
          return new Response(<value>);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors
    Examples:
      | attribute         | value                                  |
      | MapRequestPayload | $payload->name                         |
      | MapQueryString    | $payload->name                         |
      | MapRequestPayload | (string) $payload->address?->street    |

  Scenario: A mapped request object is printed in a response by a service
    Given I have the following code
      """
      final class Greeter
      {
        public function greet(Payload $payload): Response
        {
          return new Response($payload->name);
        }
      }

      final class Controller
      {
        #[Route('/payload')]
        public function payload(#[MapRequestPayload] Payload $payload, Greeter $greeter): Response
        {
          return $greeter->greet($payload);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario: A property of the data class of a form is printed in a response
    Given I have the following code
      """
      /** @extends AbstractType<Payload> */
      final class PayloadType extends AbstractType
      {
        #[\Override]
        public function configureOptions(OptionsResolver $resolver): void
        {
          $resolver->setDefaults(['data_class' => Payload::class]);
        }
      }

      final class Printer
      {
        public function print(Payload $payload): Response
        {
          return new Response($payload->name);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors

  Scenario Outline: Values the user cannot shape are not reported
    Given I have the following code
      """
      final class Controller
      {
        <route>
        public function show(<parameter>): Response
        {
          return new Response(<value>);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
    Examples:
      | route              | parameter                                     | value                    |
      | #[Route('/{id}')]  | int $id                                       | (string) $id             |
      | #[Route('/payload')] | #[MapRequestPayload] Payload $payload       | (string) $payload->count |
      |                    | string $name                                  | $name                    |
      |                    | Payload $payload                              | $payload->name           |
